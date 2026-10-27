<?php

namespace App\Http\Controllers;

use App\Enums\ChannelLogoType;
use App\Enums\DefaultAuthMode;
use App\Enums\PlaylistChannelId;
use App\Facades\PlaylistFacade;
use App\Facades\ProxyFacade;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Network;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Services\ChannelNumberSequence;
use App\Services\PlaylistUrlService;
use Illuminate\Http\Request;
use Illuminate\Support\LazyCollection;

class PlaylistGenerateController extends Controller
{
    /**
     * Sub-channel tiebreak for custom playlist ordering: the pivot's minor when the
     * pivot number is set (it overrides as a pair), else the channel's own minor.
     */
    private const CUSTOM_CHANNEL_MINOR_ORDER = 'COALESCE(CASE WHEN NULLIF(channel_custom_playlist.channel_number, 0) IS NOT NULL THEN channel_custom_playlist.channel_minor ELSE channels.channel_minor END, 0)';

    public function __invoke(Request $request, string $uuid)
    {
        // Fetch the playlist
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Handle network playlists separately
        if ($playlist instanceof Playlist && $playlist->is_network_playlist) {
            return $this->generateNetworkPlaylist($request, $playlist);
        }

        // Ensure M3U output is enabled
        if ($response = $this->outputDisabledResponse($playlist->m3u_enabled)) {
            return $response;
        }

        switch (class_basename($playlist)) {
            case 'Playlist':
                $type = 'standard';
                break;
            case 'MergedPlaylist':
                $type = 'merged';
                break;
            case 'CustomPlaylist':
                $type = 'custom';
                break;
            case 'PlaylistAlias':
                $type = 'alias';
                break;
            default:
                return response()->json(['Error' => 'Invalid Playlist Type'], 400);
        }

        // Check auth
        [$authorized, $usedAuth] = $this->resolveOutputAuth($playlist, $request->get('username'), $request->get('password'));
        if (! $authorized) {
            return response()->json(['Error' => 'Unauthorized'], 401);
        }

        // Check if proxy enabled
        if ($request->has('proxy')) {
            $proxyEnabled = $request->input('proxy') === 'true';
        } else {
            $proxyEnabled = $playlist->enable_proxy;
        }

        // Check if user has permission to use proxy
        // If not, force proxy to be disabled regardless of settings
        if ($proxyEnabled && ! $playlist->user->canUseProxy()) {
            $proxyEnabled = false;
        }

        $logoProxyEnabled = $playlist->enable_logo_proxy;
        $tvgTypeoutputEnabled = $playlist->output_tvg_type ?? false;

        // Get the base URL
        $baseUrl = ProxyFacade::getBaseUrl();

        // Pre-compute the global MediaFlow settings (checked once); whether it actually
        // applies is decided per-channel below, since the proxy can be forced on for a
        // single channel (or its source playlist) even when the playlist-level toggle is off.
        $mediaFlowRewriteStreamUrls = PlaylistFacade::mediaFlowProxyEnabled()
            && (PlaylistFacade::getMediaFlowSettings()['mediaflow_proxy_rewrite_stream_urls'] ?? false);

        // Build the channel query
        $channels = self::getChannelQuery($playlist);
        // Get all channels at once for global sort ordering (replaces cursor() streaming).
        // Memory trade-off: ~10-20MB for 10k channels × 2 groups avg — acceptable.
        $channelCollection = $channels->get();

        // Resolve the custom playlist UUID used by buildSortedEmissionList() to filter tags.
        $playlistUuid = ($playlist instanceof PlaylistAlias && $playlist->custom_playlist_id)
            ? ($playlist->customPlaylist?->uuid ?? $playlist->uuid)
            : $playlist->uuid;

        // Build sorted emission list (per-group sort from taggables.sort → fallback to channel sort).
        $entries = self::buildSortedEmissionList($channelCollection, $playlistUuid);

        return response()->stream(
            function () use ($entries, $baseUrl, $playlist, $proxyEnabled, $logoProxyEnabled, $type, $tvgTypeoutputEnabled, $usedAuth, $mediaFlowRewriteStreamUrls) {
                // Set the auth details (no matched credentials means the public UUID login applies)
                if ($usedAuth) {
                    $username = urlencode($usedAuth->username);
                    $password = urlencode($usedAuth->password);
                } else {
                    $username = urlencode($playlist->user->name);
                    $password = urlencode($playlist->uuid);
                }

                // Output the enabled channels (sorted by per-group sort position).
                $epgUrl = route('epg.generate', ['uuid' => $playlist->uuid]);
                echo "#EXTM3U x-tvg-url=\"$epgUrl\" \n";
                $numberSequence = new ChannelNumberSequence($playlist);
                $idChannelBy = $playlist->id_channel_by;

                // Track assigned channel numbers to prevent drift for multi-group channels.
                // A channel in N groups gets the same `channelNo` for all N entries, not N
                // incrementing values that would shift subsequent numbering.
                $assignedChannelNumbers = [];

                // Memoized by playlist_id so a batch of channels sharing the same source
                // playlist (the common case) only resolves it once rather than per row.
                $sourcePlaylistCache = [];
                foreach ($entries as $entry) {
                    // Channel and group come from the sorted emission list.
                    $channel = $entry['channel'];
                    $group = $entry['group_name'];

                    [$channelSourcePlaylist, $channelProxyEnabled, $channelMfRewriteEnabled] = self::resolveChannelProxyContext(
                        $playlist,
                        $channel,
                        $proxyEnabled,
                        $mediaFlowRewriteStreamUrls,
                        $sourcePlaylistCache
                    );

                    // Get the title and name
                    $title = $channel->title_custom ?? $channel->title;
                    $name = $channel->name_custom ?? $channel->name;
                    $url = PlaylistUrlService::getChannelUrl($channel, $playlist);
                    // Use selected EPG fields (avoids N+1 query for epgChannel relation)
                    $epgIcon = $channel->epg_icon ?? null;
                    $epgIconCustom = $channel->epg_icon_custom ?? null;
                    $isCustomContext = ($type === 'custom') || ($type === 'alias' && ! empty($playlist->custom_playlist_id));

                    // Channel number is shared across all group instances for this channel,
                    // so a multi-group channel doesn't consume N sequence numbers.
                    $channelId = $channel->id;
                    if (isset($assignedChannelNumbers[$channelId])) {
                        $channelNo = $assignedChannelNumbers[$channelId];
                    } else {
                        $channelNo = $numberSequence->next($channel->resolveChannelNumber($isCustomContext));
                        $assignedChannelNumbers[$channelId] = $channelNo;
                    }

                    $timeshift = $channel->shift ?? 0;
                    $stationId = $channel->station_id ?? '';
                    $epgShift = $channel->tvg_shift ?? 0;

                    // A group folded into a merged group reports the parent's name for
                    // all standard (non-custom) output. Custom playlists key their grouping
                    // off the per-tag entries from buildSortedEmissionList(), so leave
                    // those untouched.
                    if (! $isCustomContext && $channel->merged_group_name !== null && $channel->merged_group_name !== '') {
                        $group = $channel->merged_group_name;
                    }

                    // Get the TVG ID
                    $tvgId = $channel->resolveTvgId($idChannelBy, $channelNo);

                    // If no TVG ID still, fallback to the channel source ID or internal ID as a last resort
                    if (empty($tvgId)) {
                        $tvgId = $channel->source_id ?? $channel->id;
                    }

                    // Get the icon
                    $icon = '';
                    if ($channel->logo) {
                        // Logo override takes precedence
                        $icon = $channel->logo;
                    } elseif ($channel->logo_type === ChannelLogoType::Epg && ($epgIconCustom || $epgIcon)) {
                        $icon = $epgIconCustom ?? $epgIcon ?? '';
                    } elseif ($channel->logo_type === ChannelLogoType::Channel) {
                        $icon = $channel->logo ?? $channel->logo_internal ?? '';
                    }
                    if (empty($icon)) {
                        $icon = $baseUrl.'/placeholder.png';
                    }

                    // Get the extension from the source URL
                    // Need to get clean URL first (in case URL variables are used that might not have an extension, e.g. /stream/{id})
                    $filename = parse_url($url, PHP_URL_PATH);
                    $extension = pathinfo($filename, PATHINFO_EXTENSION);
                    if (empty($extension)) {
                        if ($channelSourcePlaylist?->xtream) {
                            $extension = $channelSourcePlaylist->xtream_config['output'] ?? 'ts'; // Default to 'ts' if not set
                        }
                    }

                    if ($logoProxyEnabled && filter_var($icon, FILTER_VALIDATE_URL) && ! str_starts_with($icon, url('/'))) {
                        // Proxy the logo through the logo proxy controller
                        $icon = LogoProxyController::generateProxyUrl($icon);
                    }

                    // Format the URL in Xtream Codes format if not disabled
                    // This way we can perform additional stream analysis, check for stream limits, etc.
                    // When disabled, will return the raw URL from the channel (or the proxyfied URL if proxy enabled)
                    $useInternalXtreamFormat = ! ((config('app.disable_m3u_xtream_format') ?? false) || $playlist->disable_m3u_xtream_format);
                    if ($useInternalXtreamFormat) {
                        $urlPath = 'live';
                        if ($channel->is_vod) {
                            $urlPath = 'movie';
                            $extension = $channel->container_extension ?? 'mkv';
                        } elseif ($channelProxyEnabled) {
                            // The proxy may transcode the stream, so advertise the configured
                            // output format instead of the raw provider extension.
                            $extension = self::resolveProxyOutputFormat($channelSourcePlaylist, $playlist);
                        }
                        $url = $baseUrl."/{$urlPath}/{$username}/{$password}/".$channel->id.'.'.$extension;
                    } elseif ($channelMfRewriteEnabled) {
                        // Raw URL mode: wrap the provider URL through MediaFlow Proxy
                        $url = PlaylistFacade::buildMediaFlowStreamUrl($url);
                    }
                    $url = rtrim($url, '.');

                    // The catchup-source URL always points at our own endpoint when the proxy is
                    // enabled, regardless of whether the main URL above used the internal Xtream
                    // format, so its extension must independently reflect the proxy's configured
                    // output format rather than reusing $extension (which is only proxy-aware
                    // inside the $useInternalXtreamFormat branch).
                    $catchupExtension = ($channelProxyEnabled && ! $channel->is_vod)
                        ? self::resolveProxyOutputFormat($channelSourcePlaylist, $playlist)
                        : $extension;

                    // Make sure TVG ID only contains characters and numbers
                    $tvgId = preg_replace(config('dev.tvgid.regex'), '', $tvgId);

                    // Emit one EXTINF + URL per entry (one group per emission).
                    $this->emitChannelOutput(
                        playlist: $playlist,
                        channel: $channel,
                        title: $title,
                        name: $name,
                        url: $url,
                        group: $group,
                        channelNo: $channelNo,
                        tvgId: $tvgId,
                        icon: $icon,
                        timeshift: $timeshift,
                        stationId: $stationId,
                        epgShift: $epgShift,
                        proxyEnabled: $channelProxyEnabled,
                        useInternalXtreamFormat: $useInternalXtreamFormat,
                        tvgTypeoutputEnabled: $tvgTypeoutputEnabled,
                        baseUrl: $baseUrl,
                        username: $username,
                        password: $password,
                        catchupExtension: $catchupExtension,
                    );
                }

                // If the playlist includes series in M3U, include the series episodes
                if ($playlist->include_series_in_m3u) {
                    // A Custom Playlist (or an alias of one) categorizes a series by its
                    // own category tag when one is saved, rather than the series' original
                    // provider category - the same distinction get_series_categories/
                    // get_series already make for the Xtream output.
                    $seriesCategoryTagType = ($playlist instanceof CustomPlaylist)
                        ? $playlist->uuid.'-category'
                        : (($playlist instanceof PlaylistAlias && $playlist->custom_playlist_id)
                            ? $playlist->customPlaylist->uuid.'-category'
                            : null);

                    // Get the seasons
                    $seriesQuery = $playlist->series()
                        ->where('series.enabled', true)
                        ->with(array_filter([
                            'category.parent',
                            $seriesCategoryTagType !== null ? 'tags' : null,
                            'episodes' => function ($q) {
                                $q->where('episodes.enabled', true);
                            },
                        ]));
                    foreach (self::seriesKeysetLazy($seriesQuery, 50) as $s) {
                        // Get series movie DB ID's as fallbacks for episode
                        $movieDbIds = $s->getMovieDbIds() ?? [];
                        $seriesTmdbId = $movieDbIds['tmdb'] ?? $movieDbIds['tvdb'] ?? $movieDbIds['imdb'] ?? null;

                        $customCategory = $seriesCategoryTagType !== null
                            ? $s->tags->where('type', $seriesCategoryTagType)->first()
                            : null;

                        // Append the episodes
                        foreach ($s->episodes as $episode) {
                            // Set channel variables
                            $channelNo = $numberSequence->advance();
                            $group = $customCategory?->name ?? $s->category->effective_name ?? 'Seasons';
                            $name = $s->name;
                            $url = PlaylistUrlService::getEpisodeUrl($episode, $playlist);
                            $title = $episode->title;
                            $runtime = $episode->info['duration_secs'] ?? -1;
                            $icon = $episode->info['movie_image'] ?? $s->cover ?? '';
                            if (empty($icon)) {
                                $icon = url('/placeholder.png');
                            }

                            if ($logoProxyEnabled) {
                                $icon = LogoProxyController::generateProxyUrl($icon);
                            }
                            if (! ((config('app.disable_m3u_xtream_format') ?? false) || $playlist->disable_m3u_xtream_format) || $proxyEnabled) {
                                $containerExtension = $episode->container_extension ?? 'mp4';
                                $url = $baseUrl."/series/{$username}/{$password}/".$episode->id.".{$containerExtension}";
                            } elseif (! $proxyEnabled && $mediaFlowRewriteStreamUrls) {
                                // Raw URL mode: wrap the provider URL through MediaFlow Proxy
                                $url = PlaylistFacade::buildMediaFlowStreamUrl($url);
                            }
                            $url = rtrim($url, '.');

                            // Get the TVG ID
                            switch ($idChannelBy) {
                                case PlaylistChannelId::ChannelId:
                                    $tvgId = $episode->id;
                                    break;
                                case PlaylistChannelId::Number:
                                    $tvgId = $channelNo;
                                    break;
                                case PlaylistChannelId::Name:
                                    $tvgId = $name;
                                    break;
                                case PlaylistChannelId::Title:
                                    $tvgId = $name;
                                    break;
                                default:
                                    $tvgId = $episode->id;
                                    break;
                            }

                            $extInf = "#EXTINF:$runtime";
                            // Fallback to series TMDB ID if episode not set
                            $episodeTmdbId = $episode->tmdb_id ?: ($episode->info['tmdb_id'] ?? null) ?: $seriesTmdbId;
                            if ($episodeTmdbId) {
                                $extInf .= " tmdb-id=\"{$episodeTmdbId}\"";
                            }

                            // Output TVG type if enabled
                            if ($tvgTypeoutputEnabled) {
                                $extInf .= ' tvg-type="tvshows"';
                            }

                            // Add season and episode information
                            $seasonNum = $episode->season;
                            $episodeNum = $episode->episode_num;
                            if ($seasonNum !== null) {
                                $extInf .= " tvg-season=\"{$seasonNum}\"";
                            }
                            if ($episodeNum !== null) {
                                $extInf .= " tvg-episode=\"{$episodeNum}\"";
                            }
                            $extInf .= " tvg-chno=\"$channelNo\" tvg-id=\"$tvgId\" tvg-name=\"$name\" tvg-logo=\"$icon\" group-title=\"$group\"";
                            echo "$extInf,".$title."\n";
                            echo $url."\n";
                        }
                    }
                }

                // Networks are now synced as actual Channel records with network_id
                // They will be included automatically in the channel query above
            },
            200,
            [
                'Access-Control-Allow-Origin' => '*',
                'Content-Type' => 'audio/x-mpegurl',
                'Cache-Control' => 'no-cache, must-revalidate',
                'Pragma' => 'no-cache',
            ]
        );
    }

    public function hdhr(Request $request, string $uuid, ?string $username = null, ?string $password = null)
    {
        // Fetch the playlist so we can send a 404 if not found
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Ensure HDHR output is enabled
        if ($response = $this->outputDisabledResponse($playlist->hdhr_enabled)) {
            return $response;
        }

        // Setup the HDHR device info (pass through optional path auth)
        $deviceInfo = $this->getDeviceInfo($request, $playlist, $username, $password);
        // Ensure XML special characters are escaped (e.g., '&' -> '&amp;') to avoid parser errors
        $deviceInfoXml = collect($deviceInfo)->map(function ($value, $key) {
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            $value = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            return "<{$key}>{$value}</{$key}>";
        })->implode('');
        $xmlResponse = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><root>$deviceInfoXml</root>";

        // Return the XML response to mimic the HDHR device
        return response($xmlResponse)->header('Content-Type', 'application/xml');
    }

    public function hdhrOverview(Request $request, string $uuid, ?string $username = null, ?string $password = null)
    {
        // Fetch the playlist so we can send a 404 if not found
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Ensure HDHR output is enabled
        if ($response = $this->outputDisabledResponse($playlist->hdhr_enabled)) {
            return $response;
        }

        // Check auth (prefer path-based auth if present)
        [$authorized] = $this->resolveOutputAuth($playlist, $username ?? $request->get('username'), $password ?? $request->get('password'));
        if (! $authorized) {
            return response()->json(['Error' => 'Unauthorized'], 401);
        }

        return view('hdhr', [
            'playlist' => $playlist,
        ]);
    }

    public function hdhrDiscover(Request $request, string $uuid, ?string $username = null, ?string $password = null)
    {
        // Fetch the playlist so we can send a 404 if not found
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Ensure HDHR output is enabled
        if ($response = $this->outputDisabledResponse($playlist->hdhr_enabled)) {
            return $response;
        }

        // Return the HDHR device info (pass through optional path auth)
        return $this->getDeviceInfo($request, $playlist, $username, $password);
    }

    public function hdhrLineup(Request $request, string $uuid, ?string $username = null, ?string $password = null)
    {
        // Fetch the playlist
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Ensure HDHR output is enabled
        if ($response = $this->outputDisabledResponse($playlist->hdhr_enabled)) {
            return $response;
        }

        // Build the channel query
        $channels = self::getChannelQuery($playlist);

        // Check auth (prefer path-based auth if present)
        [$authorized, $usedAuth] = $this->resolveOutputAuth($playlist, $username ?? $request->get('username'), $password ?? $request->get('password'));
        if (! $authorized) {
            return response()->json(['Error' => 'Unauthorized'], 401);
        }

        // Set the auth details (no matched credentials means the public UUID login applies)
        if ($usedAuth) {
            $username = $usedAuth->username;
            $password = $usedAuth->password;
        } else {
            $username = $playlist->user->name;
            $password = $playlist->uuid;
        }

        // Check if proxy enabled
        $idChannelBy = $playlist->id_channel_by;
        $numberSequence = new ChannelNumberSequence($playlist);
        $isCustomContext = ($playlist instanceof CustomPlaylist) ||
            ($playlist instanceof PlaylistAlias && ! empty($playlist->custom_playlist_id));

        // Stream the JSON response to avoid loading all channels into memory.
        $cursor = $channels->cursor();
        $headers = [
            'Content-Type' => 'application/json',
        ];

        // Check if user has permission to use proxy
        // If not, force proxy to be disabled regardless of settings
        $proxyEnabled = false;
        if ($playlist->user->canUseProxy()) {
            $proxyEnabled = $playlist->enable_proxy;
        }

        // Pre-compute the global MediaFlow settings (checked once); whether it actually
        // applies is decided per-channel below, since the proxy can be forced on for a
        // single channel (or its source playlist) even when the playlist-level toggle is off.
        $mediaFlowRewriteStreamUrls = PlaylistFacade::mediaFlowProxyEnabled()
            && (PlaylistFacade::getMediaFlowSettings()['mediaflow_proxy_rewrite_stream_urls'] ?? false);

        // Pre-compute the base URL for stream URL generation (used if proxy enabled or internal Xtream format used)
        $baseUrl = ProxyFacade::getBaseUrl();
        $useInternalXtreamFormat = ! ((config('app.disable_m3u_xtream_format') ?? false) || $playlist->disable_m3u_xtream_format);

        return response()->stream(function () use ($cursor, $baseUrl, $username, $password, $playlist, $idChannelBy, $proxyEnabled, $mediaFlowRewriteStreamUrls, $isCustomContext, $useInternalXtreamFormat, $numberSequence) {
            $first = true;
            echo '[';
            // Memoized by playlist_id so a batch of channels sharing the same source
            // playlist (the common case) only resolves it once rather than per row.
            $sourcePlaylistCache = [];
            foreach ($cursor as $channel) {
                [$channelSourcePlaylist, $channelProxyEnabled, $channelMfRewriteEnabled] = self::resolveChannelProxyContext(
                    $playlist,
                    $channel,
                    $proxyEnabled,
                    $mediaFlowRewriteStreamUrls,
                    $sourcePlaylistCache
                );

                $url = PlaylistUrlService::getChannelUrl($channel, $playlist);

                $channelNo = $channel->resolveChannelNumber($isCustomContext);

                $channelNo = $numberSequence->next($channelNo);

                // Get the TVG ID
                $tvgId = $channel->resolveTvgId($idChannelBy, $channelNo);

                // If no TVG ID still, fallback to the channel source ID or internal ID as a last resort
                if (empty($tvgId)) {
                    $tvgId = $channel->source_id ?? $channel->id;
                }

                // Get the extension from the source URL
                // Need to get clean URL first (in case URL variables are used that might not have an extension, e.g. /stream/{id})
                $filename = parse_url($url, PHP_URL_PATH);
                $extension = pathinfo($filename, PATHINFO_EXTENSION);
                if (empty($extension)) {
                    if ($channelSourcePlaylist?->xtream) {
                        $extension = $channelSourcePlaylist->xtream_config['output'] ?? 'ts'; // Default to 'ts' if not set
                    }
                }

                // Format the URL in Xtream Codes format if not disabled
                // This way we can perform additional stream analysis, check for stream limits, etc.
                // When disabled, will return the raw URL from the channel (or the proxyfied URL if proxy enabled)
                if ($useInternalXtreamFormat) {
                    $urlPath = 'live';
                    if ($channel->is_vod) {
                        $urlPath = 'movie';
                        $extension = $channel->container_extension ?? 'mkv';
                    } elseif ($channelProxyEnabled) {
                        // The proxy may transcode the stream, so advertise the configured
                        // output format instead of the raw provider extension.
                        $extension = self::resolveProxyOutputFormat($channelSourcePlaylist, $playlist);
                    }
                    $url = $baseUrl."/{$urlPath}/{$username}/{$password}/".$channel->id.'.'.$extension;
                } elseif ($channelMfRewriteEnabled) {
                    // Raw URL mode: wrap the provider URL through MediaFlow Proxy
                    $url = PlaylistFacade::buildMediaFlowStreamUrl($url);
                }
                $url = rtrim($url, '.');

                $item = [
                    'GuideNumber' => (string) $tvgId,
                    'GuideName' => $channel->title_custom ?? $channel->title,
                    'URL' => $url,
                ];

                if (! $first) {
                    echo ',';
                }
                echo json_encode($item);
                $first = false;
            }
            echo ']';
        }, 200, $headers);
    }

    public function hdhrLineupStatus(Request $request, string $uuid)
    {
        // No need to fetch, status is same for all...
        return response()->json([
            'ScanInProgress' => 0,
            'ScanPossible' => 1,
            'Source' => 'Cable',
            'SourceList' => ['Cable'],
        ]);
    }

    private function outputDisabledResponse(bool $outputEnabled)
    {
        if ($outputEnabled) {
            return null;
        }

        return response()->json(['Error' => 'Output disabled'], 403);
    }

    /**
     * Check the credentials sent to an M3U/HDHR output. Playlist Auths (or an alias'
     * own credentials) always work. Otherwise the playlist's default login decides:
     * UUID login keeps the output public while no auths are assigned, Custom Password
     * requires the owner's name and custom password, and Disabled requires an auth.
     *
     * Returns whether access is allowed, and the matched credentials (null for the
     * public UUID login).
     *
     * @param  Playlist|MergedPlaylist|CustomPlaylist|PlaylistAlias  $playlist
     * @return array{0: bool, 1: object{username: string, password: string}|null}
     */
    private function resolveOutputAuth($playlist, mixed $username, mixed $password): array
    {
        $auths = $playlist->playlistAuths()->where('enabled', true)->get();
        // For PlaylistAlias, also check direct alias credentials as fallback
        if ($auths->isEmpty() && $playlist instanceof PlaylistAlias && $playlist->authObject) {
            $auths = collect([$playlist->authObject]);
        }

        $matchedAuth = $auths->first(fn ($auth) => $username === $auth->username && $password === $auth->password);
        if ($matchedAuth) {
            return [true, $matchedAuth];
        }

        if ($playlist->getDefaultAuthMode() === DefaultAuthMode::Uuid) {
            return [$auths->isEmpty(), null];
        }

        // Custom Password login (null when the default login is disabled)
        $defaultCredentials = $playlist->getDefaultAuthCredentials();
        if ($defaultCredentials && $username === $defaultCredentials->username && $password === $defaultCredentials->password) {
            return [true, $defaultCredentials];
        }

        return [false, null];
    }

    private function getDeviceInfo(Request $request, $playlist, ?string $username = null, ?string $password = null)
    {
        // Check auth (prefer path-based auth if present)
        [, $usedAuth] = $this->resolveOutputAuth($playlist, $username ?? $request->get('username'), $password ?? $request->get('password'));

        // Return the HDHR device info
        $uuid = $playlist->uuid;
        $tunerCount = (int) $playlist->streams === 0
            ? ($xtreamStatus['user_info']['max_connections'] ?? $playlist->streams ?? 1)
            : $playlist->streams;
        $tunerCount = max($tunerCount, 1); // Ensure at least 1 tuner
        $deviceId = substr($uuid, 0, 8);
        $baseUrl = ProxyFacade::getBaseUrl();
        $baseUrl = $baseUrl."/{$uuid}/hdhr";

        // Prefer path-based auth for HDHR (clients typically ignore query strings)
        $authPath = '';
        if ($usedAuth) {
            $authPath = '/'.rawurlencode($usedAuth->username).'/'.rawurlencode($usedAuth->password);
        }

        return [
            'DeviceID' => $deviceId,
            'FriendlyName' => "{$playlist->name} HDHomeRun",
            'ModelNumber' => 'HDHR5-4K',
            'FirmwareName' => 'hdhomerun5_firmware_20240425',
            'FirmwareVersion' => '20240425',
            'DeviceAuth' => 'test_auth_token',
            'BaseURL' => $baseUrl.$authPath,
            'LineupURL' => $baseUrl.$authPath.'/lineup.json',
            'TunerCount' => $tunerCount,
        ];
    }

    /**
     * Resolve a channel's proxy context: its own source playlist (memoized in
     * $sourcePlaylistCache by playlist_id, so a batch of channels sharing the same
     * source playlist only resolves it once instead of once per row), whether the
     * proxy is effectively enabled for it, and whether MediaFlow's raw-URL rewrite
     * applies.
     *
     * A channel can force the proxy path even when the playlist-level toggle is
     * off: its own per-channel override, or its source playlist pooling provider
     * profiles (profile selection/pool distribution only happens on the proxy
     * path). Uses the same `Channel::needsProxy()` rule as
     * XtreamStreamController::handleLive()/handleVod() so the two can't drift apart.
     *
     * @return array{0: ?Playlist, 1: bool, 2: bool}
     */
    private static function resolveChannelProxyContext(
        $playlist,
        Channel $channel,
        bool $proxyEnabled,
        bool $mediaFlowRewriteStreamUrls,
        array &$sourcePlaylistCache
    ): array {
        $sourcePlaylistId = $channel->playlist_id;
        if ($sourcePlaylistId !== null) {
            if (! array_key_exists($sourcePlaylistId, $sourcePlaylistCache)) {
                $sourcePlaylistCache[$sourcePlaylistId] = Playlist::find($sourcePlaylistId, ['id', 'xtream', 'profiles_enabled', 'xtream_config']);
            }
            $channelSourcePlaylist = $sourcePlaylistCache[$sourcePlaylistId];
        } else {
            $channelSourcePlaylist = null;
        }

        $channelProxyEnabled = Channel::needsProxy(
            channelEnableProxy: (bool) $channel->enable_proxy,
            playlistEnableProxy: $proxyEnabled,
            requestProxyFlag: false,
            sourcePlaylistProfilesEnabled: $channelSourcePlaylist->profiles_enabled ?? false,
            userCanUseProxy: $playlist->user->canUseProxy(),
        );

        return [$channelSourcePlaylist, $channelProxyEnabled, ! $channelProxyEnabled && $mediaFlowRewriteStreamUrls];
    }

    /**
     * Resolve the proxy's configured output format extension for a live channel,
     * preferring the channel's own source playlist's xtream_config and falling back
     * to the requested playlist's. A PlaylistAlias's own xtream_config is a
     * normalized list of provider account configs (not a single keyed config), so
     * its actual output setting must be read from the real playlist it wraps via
     * getEffectivePlaylist() instead - mirrors the allowed_output_formats
     * resolution in XtreamApiController::__invoke().
     */
    private static function resolveProxyOutputFormat(?Playlist $channelSourcePlaylist, $playlist): string
    {
        $proxyOutput = $channelSourcePlaylist?->xtream_config['output'] ?? null;

        if ($proxyOutput === null) {
            $contextXtreamConfig = $playlist instanceof PlaylistAlias
                ? ($playlist->getEffectivePlaylist()?->xtream_config ?? null)
                : ($playlist->xtream_config ?? null);
            $proxyOutput = $contextXtreamConfig['output'] ?? 'ts';
        }

        return $proxyOutput === 'hls' ? 'm3u8' : $proxyOutput;
    }

    /**
     * Build the base query for channels for a playlist.
     */
    public static function getChannelQuery($playlist, ?bool $isVod = null): mixed
    {
        // Build the base query for channels. We'll use cursor() to stream
        // results rather than loading all channels into memory.
        // For tag lookups, use the custom playlist UUID when dealing with aliases of custom playlists
        $playlistUuid = ($playlist instanceof PlaylistAlias && $playlist->custom_playlist_id)
            ? ($playlist->customPlaylist?->uuid ?? $playlist->uuid)
            : $playlist->uuid;
        $query = $playlist->channels()
            ->leftJoin('groups', 'channels.group_id', '=', 'groups.id')
            // A group folded into a merged group defers its displayed name and sort
            // order to that parent. Resolved here in SQL so output and ordering stay
            // consistent without writing anything back to the channel rows.
            ->leftJoin('groups as parent_groups', 'groups.parent_id', '=', 'parent_groups.id')
            ->where('channels.enabled', true)
            ->when($isVod === true, fn ($q) => $q->where('channels.is_vod', true))
            ->when($isVod === false, fn ($q) => $q->where('channels.is_vod', false))
            ->when($isVod === null && ! $playlist->include_vod_in_m3u, fn ($q) => $q->where('channels.is_vod', false))
            // Select the channel columns and also pull through group name and (for custom)
            // the custom tag name/order so we can order in SQL and avoid a PHP-side resort.
            ->selectRaw('channels.*')
            ->selectRaw('groups.name as group_name')
            ->selectRaw('parent_groups.name as merged_group_name')
            ->selectRaw('parent_groups.id as merged_group_id')
            ->selectRaw('COALESCE(parent_groups.sort_order, groups.sort_order) as group_sort_order')
            ->selectRaw('groups.aed_profile_id as group_aed_profile_id');

        // Join EPG channel data to avoid N+1 queries and select common fields
        $query->leftJoin('epg_channels', 'channels.epg_channel_id', '=', 'epg_channels.id')
            ->selectRaw('epg_channels.epg_id as epg_id')
            ->selectRaw('epg_channels.icon as epg_icon')
            ->selectRaw('epg_channels.icon_custom as epg_icon_custom')
            // Alias the external EPG channel identifier to avoid clobbering the FK attribute
            ->selectRaw('epg_channels.channel_id as epg_channel_key');

        // If custom playlist (or alias of one), use correlated subqueries to retrieve the
        // custom tag order/name without JOINing taggables, which would produce duplicate
        // rows for channels that belong to more than one tag in this playlist.
        $isCustomContext = $playlist instanceof CustomPlaylist
            || ($playlist instanceof PlaylistAlias && ! empty($playlist->custom_playlist_id));

        // Optional flat channel number ordering, ahead of any group ordering. Channels
        // without a number (NULL or 0, matching the output's "no number" check) go last
        // on every driver, then fall through to the standard ordering below. A custom
        // playlist's pivot number wins when set, as it does for tvg-chno. 0 is folded
        // into NULL so unnumbered channels tie here regardless of driver NULL ordering.
        // Sub-channels ("5.1") follow their major number, from the same source as it.
        if ($playlist->sort_by_channel_number) {
            if ($isCustomContext) {
                $query->orderByRaw('CASE WHEN COALESCE(NULLIF(channel_custom_playlist.channel_number, 0), NULLIF(channels.channel, 0)) IS NULL THEN 1 ELSE 0 END')
                    ->orderByRaw('COALESCE(NULLIF(channel_custom_playlist.channel_number, 0), NULLIF(channels.channel, 0))')
                    ->orderByRaw(self::CUSTOM_CHANNEL_MINOR_ORDER);
            } else {
                $query->orderByRaw('CASE WHEN NULLIF(channels.channel, 0) IS NULL THEN 1 ELSE 0 END')
                    ->orderByRaw('NULLIF(channels.channel, 0)')
                    ->orderByRaw('COALESCE(channels.channel_minor, 0)');
            }
        }

        if ($isCustomContext) {
            // Select the first custom group name as a fallback (used when no tags are loaded).
            // Multi-group emission is handled in PHP by iterating through $channel->tags,
            // so no LEFT JOIN to taggables/tags is needed here.
            $orderSubquery = '(SELECT MIN(t.order_column) FROM taggables tb INNER JOIN tags t ON t.id = tb.tag_id WHERE tb.taggable_id = channels.id AND tb.taggable_type = ? AND t.type = ?)';

            $query->selectRaw("{$orderSubquery} as custom_order", [Channel::class, $playlistUuid])
                ->selectRaw(
                    '(SELECT t.name FROM taggables tb INNER JOIN tags t ON t.id = tb.tag_id WHERE tb.taggable_id = channels.id AND tb.taggable_type = ? AND t.type = ? ORDER BY t.order_column ASC LIMIT 1) as custom_group_name',
                    [Channel::class, $playlistUuid]
                )
                // Pivot sort (per-playlist channel position) is the primary ordering key.
                // This ensures channels appear at their saved position regardless of group membership.
                ->selectRaw('channel_custom_playlist.sort as ccp_sort');

            // Per-alias custom live group ordering (optional), ranked by the group the
            // client is actually shown: the channel's custom tag, or the provider group
            // name it falls back to when untagged. Groups outside the saved order (and
            // VOD groups) fall through to the playlist's own ordering below.
            if ($playlist instanceof PlaylistAlias && $playlist->hasCustomLiveGroupSort()) {
                $order = array_values($playlist->getLiveGroupSortOrder());
                $grammar = $playlist->getConnection()->getQueryGrammar();

                // Tag names are translatable JSON, so let the grammar emit the driver's
                // own extraction (json_extract on SQLite, ->> on PostgreSQL).
                $effectiveGroupName = sprintf(
                    'COALESCE((SELECT %s FROM taggables tb INNER JOIN tags t ON t.id = tb.tag_id WHERE tb.taggable_id = channels.id AND tb.taggable_type = ? AND t.type = ? ORDER BY t.order_column ASC LIMIT 1), %s)',
                    $grammar->wrap('t.name->en'),
                    $grammar->wrap('channels.group'),
                );

                $whenClauses = [];
                foreach ($order as $index => $groupName) {
                    $whenClauses[] = "WHEN ? THEN {$index}";
                }
                $elseValue = count($order);

                $query->orderByRaw(
                    "CASE {$effectiveGroupName} ".implode(' ', $whenClauses)." ELSE {$elseValue} END",
                    array_merge([Channel::class, $playlistUuid], $order)
                );
            }

            // Primary ordering: per-playlist channel position, falling back to channel sort.
            $query->orderByRaw('COALESCE(channel_custom_playlist.sort, channels.sort)')
                // Tag order / source-group sort order, demoted to tiebreakers by the
                // pivot-sort primary key above.
                ->orderByRaw("COALESCE({$orderSubquery}, groups.sort_order)", [Channel::class, $playlistUuid])
                ->orderByRaw('COALESCE(channel_custom_playlist.channel_number, channels.channel)')
                ->orderByRaw(self::CUSTOM_CHANNEL_MINOR_ORDER)
                ->orderBy('channels.title');

            // Eager load all group tags for this playlist (for multi-group output).
            // withPivot() is redundant here since Channel::tags() already declares it (Step 3),
            // but kept explicit to document the intent and protect against trait behavior changes.
            $query->with(['tags' => function ($q) use ($playlistUuid): void {
                $q->where('type', $playlistUuid)
                    ->orderBy('order_column')
                    ->withPivot('sort');
            }]);
        } else {
            // Per-alias custom live group ordering (optional). When enabled, the
            // selected live groups are ranked by the alias's saved order; any group
            // not in that list (and VOD groups) falls back to the playlist's own
            // group sort order below.
            if ($playlist instanceof PlaylistAlias && $playlist->hasCustomLiveGroupSort()) {
                $order = array_values($playlist->getLiveGroupSortOrder());
                $whenClauses = [];
                foreach ($order as $index => $groupName) {
                    $whenClauses[] = "WHEN ? THEN {$index}";
                }
                $elseValue = count($order);
                $query->orderByRaw(
                    'CASE channels.group_internal '.implode(' ', $whenClauses)." ELSE {$elseValue} END",
                    $order
                );
            }

            // Standard ordering for non-custom playlists (merged groups rank by the parent,
            // then by each child group's own sort_order so the order set on the merged
            // group's child list drives channel output order). For an unmerged group the
            // second key just repeats the first, so ordering is unchanged.
            $query->orderByRaw('COALESCE(parent_groups.sort_order, groups.sort_order)')
                ->orderBy('groups.sort_order')
                ->orderBy('channels.sort')
                ->orderBy('channels.channel')
                ->orderByRaw('COALESCE(channels.channel_minor, 0)')
                ->orderBy('channels.title');
        }

        return $query;
    }

    /**
     * Build a flat, sorted list of (channel, tag) emission entries.
     *
     * Each entry represents one line in the M3U output or one stream in Xtream API.
     * Multi-group channels produce multiple entries, each with its own sort position.
     *
     * @param  \Illuminate\Support\Collection  $channels  Channels from getChannelQuery()
     * @param  string  $playlistUuid  The custom playlist UUID for tag filtering
     * @return array  Sorted array of emission entries
     */
    public static function buildSortedEmissionList($channels, string $playlistUuid): array
    {
        $entries = [];

        foreach ($channels as $channel) {
            $tags = $channel->tags; // Already filtered by playlist UUID via eager load

            if ($tags->isEmpty()) {
                // Channel has no group tags — emit once with fallback.
                // For custom playlists: use custom_group_name from SQL subquery (or '' if unset).
                // For standard/merged playlists: use group from LEFT JOIN groups.
                $groupName = $channel->custom_group_name ?? $channel->group ?? '';
                // Handle JSON-encoded tag names in the custom_group_name subquery result.
                if (!empty($groupName)) {
                    $decoded = json_decode($groupName, true);
                    if (is_array($decoded) && !json_last_error()) {
                        $groupName = $decoded['en'] ?? ($decoded[array_key_first($decoded)] ?? '');
                    }
                }

                $entries[] = [
                    'channel' => $channel,
                    'tag' => null,
                    // Per-group sort from taggables.sort → fallback to pivot sort (ccp_sort) → fallback to channel sort
                    'sort' => $channel->ccp_sort ?? $channel->sort ?? PHP_INT_MAX,
                    'group_name' => $groupName,
                    'group_id' => null,
                ];
            } else {
                // Emit one entry per tag, each with its own sort position
                foreach ($tags as $tag) {
                    $tagName = is_array($tag->name) ? ($tag->name['en'] ?? reset($tag->name)) : $tag->name;
                    $entries[] = [
                        'channel' => $channel,
                        'tag' => $tag,
                        // Per-group sort from taggables.sort → fallback to pivot sort (ccp_sort) → fallback to channel sort
                        'sort' => $tag->pivot->sort ?? $channel->ccp_sort ?? $channel->sort ?? PHP_INT_MAX,
                        'group_name' => $tagName,
                        'group_id' => $tag->id,  // Needed for Xtream API category_id
                    ];
                }
            }
        }

        // Sort by sort value, then by channel name as tiebreaker
        usort($entries, function ($a, $b) {
            $sortCompare = $a['sort'] <=> $b['sort'];
            if ($sortCompare !== 0) return $sortCompare;
            // Tiebreaker: channel number, then title
            $chanA = $a['channel']->channel ?? '';
            $chanB = $b['channel']->channel ?? '';
            $chanCompare = $chanA <=> $chanB;
            if ($chanCompare !== 0) return $chanCompare;
            return ($a['channel']->title ?? '') <=> ($b['channel']->title ?? '');
        });

        return $entries;
    }

    /**
     * Generate M3U output for a network playlist (outputs networks instead of channels).
     */
    protected function generateNetworkPlaylist(Request $request, Playlist $playlist)
    {
        $networks = $playlist->networks()
            ->where('enabled', true)
            ->orderBy('channel_number')
            ->orderBy('name')
            ->get();

        if ($networks->isEmpty()) {
            return response("#EXTM3U\n# No networks assigned to this playlist\n", 200, [
                'Content-Type' => 'audio/x-mpegurl',
            ]);
        }

        $baseUrl = ProxyFacade::getBaseUrl();

        return response()->stream(function () use ($networks, $baseUrl, $playlist) {
            // M3U header with EPG URL
            $epgUrl = route('epg.generate', ['uuid' => $playlist->uuid]);
            echo "#EXTM3U x-tvg-url=\"{$epgUrl}\"\n";

            foreach ($networks as $network) {
                $name = $network->name;
                $channelNumber = $network->channel_number ?? $network->id;
                $tvgId = "network-{$network->id}";
                $logo = $network->logo ?? "{$baseUrl}/placeholder.png";
                $group = $network->effective_group_name;
                $streamUrl = $network->stream_url;

                // Build EXTINF line
                $extInf = '#EXTINF:-1';
                $extInf .= " tvg-chno=\"{$channelNumber}\"";
                $extInf .= " tvg-id=\"{$tvgId}\"";
                $extInf .= " tvg-name=\"{$name}\"";
                $extInf .= " tvg-logo=\"{$logo}\"";
                $extInf .= " group-title=\"{$group}\"";
                $extInf .= ",{$name}";

                echo "{$extInf}\n";
                echo "{$streamUrl}\n";
            }
        }, 200, [
            'Content-Type' => 'audio/x-mpegurl',
            'Content-Disposition' => 'inline; filename="'.$playlist->name.'.m3u"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Streams a single channel as an EXTINF + URL pair to the output stream.
     *
     * Extracted from __invoke() so that multi-group channels can emit one entry
     * per group without duplicating the EXTINF / catchup / TVG logic.
     */
    private function emitChannelOutput(
        Playlist|MergedPlaylist|CustomPlaylist|PlaylistAlias $playlist,
        Channel $channel,
        ?string $title = '',
        ?string $name = '',
        string $url,
        string $group,
        int|string $channelNo,
        int|string $tvgId,
        string $icon,
        ?int $timeshift,
        string $stationId,
        int $epgShift,
        bool $proxyEnabled,
        bool $useInternalXtreamFormat,
        bool $tvgTypeoutputEnabled,
        string $baseUrl,
        string $username,
        string $password,
        ?string $catchupExtension = null,
    ): void {
        // Handle null title/name from channels with no title_custom or title set.
        $title ??= '';
        $name ??= '';

        // Normalize the group: empty groups become 'Uncategorized'.
        if (empty($group)) {
            $group = 'Uncategorized';
        }

        $extInf = '#EXTINF:-1';

        // Catchup / timeshift attributes.
        if (! $playlist->disable_catchup) {
            // When the proxy is enabled, or when the channel URL is served via the
            // internal Xtream format (default), we generate a catchup-source that
            // points to our own endpoint so catchup requests flow through m3u-editor
            // rather than going directly to the provider. This also ensures catchup
            // works for Xtream-imported channels that have tv_archive=1 but no
            // catchup_source URL template stored.
            // It uses the SIPTV utc/lutc query (programme start and now, as UNIX
            // epochs) on the live URL: the one catchup form Kodi, TiviMate and
            // IPTVnator all fill in the same way. The live endpoint turns it into the
            // provider's own catchup URL. As a complete URL, its mode is "default".
            if (($proxyEnabled || $useInternalXtreamFormat) && $channel->catchup) {
                $catchupExt = $catchupExtension ?: 'ts';
                $catchupSource = "{$baseUrl}/live/{$username}/{$password}/{$channel->id}.{$catchupExt}?utc={utc}&lutc={lutc}";
                $extInf .= " catchup=\"default\" catchup-source=\"{$catchupSource}\"";
            } else {
                if ($channel->catchup) {
                    $extInf .= " catchup=\"$channel->catchup\"";
                }
                if ($channel->catchup_source) {
                    $extInf .= " catchup-source=\"$channel->catchup_source\"";
                }
            }

            // The archive window: shift is stored in hours, while catchup-days and
            // the SIPTV timeshift attribute are both read as days by clients
            if ($timeshift) {
                $catchupDays = (int) ceil($timeshift / 24);
                $extInf .= " catchup-days=\"$catchupDays\" timeshift=\"$catchupDays\"";
            }
        }

        // Station / EPG shift attributes.
        if ($stationId) {
            $extInf .= " tvc-guide-stationid=\"$stationId\"";
        }
        if ($epgShift) {
            $extInf .= " tvg-shift=\"$epgShift\"";
        }

        // Output TVG type if enabled.
        if ($tvgTypeoutputEnabled) {
            $channelType = 'live'; // default for Live content

            // Channel specific tvg-type takes precedence, otherwise fallback to basic live/vod categorization
            if ($channel->tvg_type) {
                $channelType = $channel->tvg_type;
            } elseif ($channel->is_vod) {
                $channelType = 'movies'; // default for VOD
            }

            $extInf .= " tvg-type=\"{$channelType}\"";
        }

        // TMDB ID attribute.
        $tmdbId = $channel->tmdb_id ?: ($channel->info['tmdb_id'] ?? $channel->movie_data['tmdb_id'] ?? null);
        if ($tmdbId) {
            $extInf .= " tmdb-id=\"{$tmdbId}\"";
        }

        // Final EXTINF line with core attributes.
        $extInf .= " tvg-chno=\"$channelNo\" tvg-id=\"$tvgId\" tvg-name=\"$name\" tvg-logo=\"$icon\" group-title=\"".e($group).'"';
        echo "$extInf,".$title."\n";

        // Optional EXTVLCOPT lines.
        if ($channel->extvlcopt) {
            foreach ($channel->extvlcopt as $extvlcopt) {
                echo "#EXTVLCOPT:{$extvlcopt['key']}={$extvlcopt['value']}\n";
            }
        }

        // Optional KODIPROP lines.
        if ($channel->kodidrop) {
            foreach ($channel->kodidrop as $kodidrop) {
                echo "#KODIPROP:{$kodidrop['key']}={$kodidrop['value']}\n";
            }
        }

        echo $url."\n";
    }

    /**
     * Streams series using Laravel's cursor pagination ordered by (sort, id).
     * cursorPaginate() builds the compound WHERE clause and handles NULL sort values automatically.
     */
    public static function seriesKeysetLazy($query, int $chunkSize = 500): LazyCollection
    {
        return LazyCollection::make(function () use ($query, $chunkSize): \Generator {
            $cursor = null;

            do {
                $page = (clone $query)
                    ->orderBy('series.sort', 'asc')
                    ->orderBy('series.id', 'asc')
                    ->cursorPaginate($chunkSize, ['*'], 'cursor', $cursor);

                foreach ($page->items() as $item) {
                    yield $item;
                }

                $cursor = $page->nextCursor();
            } while ($cursor !== null);
        });
    }
}
