<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description"
        content="XSpann API v1 reference: authentication, endpoints, request parameters, responses and upload workflows.">
    <title>XSpann API — Developer reference</title>
    <link rel="stylesheet" href="{{ url('/docs/api-docs.css') }}">
    <script src="{{ url('/docs/api-docs.js') }}" defer></script>
</head>

<body>
    <a class="skip-link" href="#main">Skip to documentation</a>
    <header class="topbar">
        <a class="brand" href="#overview"><span class="brand-mark">x</span> xspann <span
                class="brand-divider"></span><span class="brand-label">Developers</span></a>
        <div class="top-actions"><span class="version">API v1</span><button id="export-docs" type="button"
                hidden>Export reference ↓</button><button id="menu-toggle" type="button" aria-expanded="false"
                aria-controls="sidebar" hidden>Menu</button></div>
    </header>
    <aside id="sidebar" class="sidebar" aria-label="Documentation navigation">
        <div class="search-box"><label for="search">Search documentation</label><input id="search" type="search"
                placeholder="Search endpoints…" autocomplete="off"><kbd>/</kbd></div>
        <label class="filter-label" for="access-filter">Access</label><select id="access-filter">
            <option value="all">All endpoints</option>
            <option value="public">Public / optional token</option>
            <option value="protected">Bearer token required</option>
        </select>
        <nav>
            <p class="nav-heading">GET STARTED</p>
            <a class="guide-link" href="#overview">Overview</a><a class="guide-link"
                href="#authentication">Authentication & authorization</a><a class="guide-link"
                href="#conventions">Requests, responses & errors</a><a class="guide-link"
                href="#uploads-guide">Uploading media</a><a class="guide-link" href="#schemas">Response models</a>
            <p class="nav-heading">API REFERENCE <span>{{ $endpointCount }}</span></p>
            @foreach ($groups as $group => $entries)
                <div class="nav-group">
                    <h2>{{ $group }}</h2>
                    @foreach ($entries as $endpoint)
                        <a class="endpoint-link" href="#{{ $endpoint['id'] }}"
                            data-endpoint="{{ $endpoint['id'] }}"><span
                                class="verb verb-{{ strtolower($endpoint['method']) }}">{{ $endpoint['method'] }}</span><span>{{ $endpoint['title'] }}</span></a>
                    @endforeach
                </div>
            @endforeach
            <a class="guide-link" href="#health">Health check</a>
        </nav>
    </aside>
    <main id="main">
        <section id="overview" class="hero">
            <div class="eyebrow">DOCUMENTATION <span>/</span> REST API</div>
            <h1>Build with XSpann<span>.</span></h1>
            <p class="lead">Everything you need to connect your frontend.<br>From the first sign-in to the next video.
            </p>
            <div class="hero-tags"><span>{{ $endpointCount }} endpoints</span><span>JSON responses</span><span>Bearer
                    authentication</span></div>
            <div class="base-url">
                <div><span class="label">API BASE URL</span><code id="base-url">{{ $baseUrl }}</code></div>
                <button type="button" data-copy-target="base-url">Copy</button>
            </div>
            <div class="quick-links"><a href="#authentication"><span>01</span><strong>Authenticate</strong><small>Create
                        an account & get a token ↗</small></a><a
                    href="#feedcontroller-index"><span>02</span><strong>Read the feed</strong><small>Fetch your first
                        page of videos ↗</small></a><a href="#uploads-guide"><span>03</span><strong>Publish a
                        video</strong><small>Upload, create & check status ↗</small></a></div>
        </section>
        <section id="authentication" class="guide-section">
            <div class="section-kicker">START HERE</div>
            <h2>Authentication & authorization</h2>
            <p>Public endpoints work without a token. An optional bearer token personalizes viewer state and visibility.
                Protected endpoints require a valid JWT belonging to an <strong>active, email-verified account</strong>.
            </p>
            <ol class="steps">
                <li><strong>Register</strong> with email, username and a confirmed password. The response has
                    <code>token: null</code>.
                </li>
                <li><strong>Verify your email</strong> using the complete signed link from the email, then call
                    <code>POST /auth/login</code>. Google sign-in can also issue a token.
                </li>
                <li><strong>Send the token</strong> in <code>Authorization: Bearer &lt;token&gt;</code>. Use
                    <code>data.token_expires_at</code> to manage its lifetime.
                </li>
                <li><strong>Rotate before expiry</strong> with <code>POST /auth/token/refresh</code>. Replace the old
                    token immediately. If it has expired, sign in again.</li>
            </ol>
            <div class="code-panel">
                <div class="code-header"><span>Request headers</span><button type="button"
                        data-copy-target="auth-headers">Copy</button></div>
                <pre id="auth-headers">Accept: application/json
Authorization: Bearer &lt;access-token&gt;
Content-Type: application/json</pre>
            </div>
            <div class="callout"><strong>Access is checked per resource.</strong> Only owners can update/delete videos
                or delete comments. Notifications belong to their recipient. Private videos are owner-only;
                followers-only videos also allow followers. Visibility-filtered endpoints hide blocked relationships and
                inactive users. Inaccessible resources generally return 404.</div>
            <p>Logout revokes the current token. Password reset revokes all tokens; password change preserves the
                current token and revokes the others. Google sign-in requires the frontend OAuth client to match the
                server’s configured Google client ID.</p>
        </section>
        <section id="conventions" class="guide-section">
            <div class="section-kicker">THE CONTRACT</div>
            <h2>Requests, responses & errors</h2>
            <div class="convention-grid">
                <div>
                    <h3>Sending requests</h3>
                    <p>Paths below include <code>/api/v1</code>. Send <code>Accept: application/json</code>. Use JSON
                        bodies unless an endpoint specifies multipart. For browser FormData, let the browser set
                        Content-Type and its boundary. Bodyless endpoints require no payload.</p>
                    <p>API routes use bearer authentication and do not require a CSRF token or cookie credentials.
                        Cross-origin clients must use an origin allowed by the server’s CORS configuration.</p>
                </div>
                <div>
                    <h3>Reading responses</h3>
                    <p>Successful resources are wrapped in <code>data</code>. Lists also return <code>links</code> and
                        <code>meta</code>; use <code>links.next</code> until null for page-based lists.
                        Home and Following also accept <code>pagination=cursor</code>: use
                        <code>meta.next_cursor</code> while <code>meta.has_more</code> is true.
                        Cursor feeds have no totals or numbered links. Omit the cursor to refresh;
                        HTTP 410 means the cursor expired. Each endpoint lists its limits.
                    </p>
                    <p>Examples are illustrative, not live data. IDs and counts are integers; viewer flags are booleans.
                        Resource timestamps use UTC ISO 8601 strings and may be null. Nullable text/media fields can
                        also be null.</p>
                </div>
            </div>
            <h3>Validation notation</h3>
            <p><code>required</code> means the field must be supplied; <code>sometimes</code> validates it only when
                present; <code>nullable</code> permits null. String min/max values are character lengths; file max
                values are KiB. Dotted fields describe nested JSON, and <code>*</code> applies to each array item.
                <code>in</code> lists allowed values. Do not send server-generated IDs, counts or status as writable
                fields.
            </p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Meaning / client action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>200 / 201</code></td>
                            <td>Success / created. Some idempotent actions return 200 on repeat.</td>
                        </tr>
                        <tr>
                            <td><code>401</code></td>
                            <td>Missing, expired, invalid or revoked credentials. Sign in again.</td>
                        </tr>
                        <tr>
                            <td><code>403</code></td>
                            <td>Inactive/unverified account, invalid email signature, or permission denied.</td>
                        </tr>
                        <tr>
                            <td><code>404</code></td>
                            <td>Missing or inaccessible resource, or unsupported sign-in provider.</td>
                        </tr>
                        <tr>
                            <td><code>409</code></td>
                            <td>Google account linking requires email/password sign-in.</td>
                        </tr>
                        <tr>
                            <td><code>422</code></td>
                            <td>Invalid input or business rule. Show field errors when present.</td>
                        </tr>
                        <tr>
                            <td><code>429</code></td>
                            <td>Rate limit reached. Back off; limits apply per IP, method and concrete path over one
                                minute.</td>
                        </tr>
                        <tr>
                            <td><code>5xx</code></td>
                            <td>Server or upstream failure. Avoid blindly retrying non-idempotent writes.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="convention-grid">
                <div class="code-panel">
                    <div class="code-header">Validation error · 422</div>
                    <pre>{
  "message": "Validation failed.",
  "errors": {
    "email": ["The provided credentials are incorrect."]
  }
}</pre>
                </div>
                <div class="code-panel">
                    <div class="code-header">HTTP error · 401</div>
                    <pre>{
  "message": "Unauthenticated.",
  "code": 401
}</pre>
                </div>
            </div>
            <p>Not every 422 contains <code>errors</code>: business-rule failures use the HTTP error shape with
                <code>message</code> and <code>code</code>. Only endpoints displaying a rate limit have an explicit
                route throttle; deployment limits may also apply.
            </p>
        </section>
        <section id="uploads-guide" class="guide-section">
            <div class="section-kicker">MEDIA WORKFLOW</div>
            <h2>From file to published video</h2>
            <ol class="steps">
                <li><strong>Choose an upload method.</strong> Request <a href="#uploadcontroller-video">a signed upload
                        URL</a>. For <code>signed_url</code>, PUT raw bytes to the returned URL with its headers; do not
                    attach your API token. For <code>multipart</code>, POST the file to the returned backend URL with
                    bearer authentication.</li>
                <li><strong>Or upload in chunks.</strong> Generate a UUID, split the file into pieces of at most 2 MiB,
                    then upload every zero-based index with identical metadata. Call <a
                        href="#uploadcontroller-completevideochunks">complete</a> only after all chunks succeed.</li>
                <li><strong>Create the post.</strong> Pass the resulting <code>storage_path</code> to <a
                        href="#videocontroller-store">POST /videos</a>, along with the caption, visibility and optional
                    edit settings.</li>
                <li><strong>Wait for processing.</strong> Poll your video detail endpoint. Handle
                    <code>processing</code>, <code>published</code> and <code>failed</code>. Deleted videos are
                    unavailable. An upload alone does not create a feed entry.
                </li>
            </ol>
            <div class="callout"><strong>Media URLs may expire.</strong> Private storage can return temporary playback
                URLs. Fetch the API resource again to refresh them. Keep storage_path from the upload response for post
                creation. Avatar and sound uploads return URLs to use in the corresponding profile or video fields.
            </div>
        </section>
        <div class="reference-heading">
            <div>
                <div class="section-kicker">EXPLORE THE API</div>
                <h2>Endpoint reference</h2>
            </div>
            <p id="result-count" role="status" aria-live="polite">{{ $endpointCount }} endpoints</p>
        </div>
        <p class="reference-hint">Select an endpoint to see its parameters, examples and response. Search by purpose,
            path or method.</p>
        <div id="empty-state" class="empty-state" hidden>
            <h3>No matching endpoints</h3>
            <p>Try another keyword or change the access filter.</p><button id="clear-filters" type="button">Clear
                filters</button>
        </div>
        @foreach ($groups as $group => $entries)
            <section class="endpoint-group">
                <h2 class="group-title">{{ $group }} <span>{{ count($entries) }}</span></h2>
                @foreach ($entries as $endpoint)
                    <details class="endpoint" id="{{ $endpoint['id'] }}"
                        data-auth="{{ $endpoint['auth'] ? 'protected' : 'public' }}"
                        data-search="{{ strtolower($group . ' ' . $endpoint['title'] . ' ' . $endpoint['method'] . ' ' . $endpoint['uri'] . ' ' . $endpoint['description']) }}">
                        <summary><span
                                class="verb verb-{{ strtolower($endpoint['method']) }}">{{ $endpoint['method'] }}</span><code>{{ $endpoint['uri'] }}</code><span
                                class="endpoint-title">{{ $endpoint['title'] }}</span><span class="access-icon"
                                title="{{ $endpoint['auth'] ? 'Bearer token required' : 'Public, optional token' }}">{{ $endpoint['auth'] ? 'TOKEN' : 'PUBLIC' }}</span><span
                                class="chevron" aria-hidden="true">⌄</span></summary>
                        <div class="endpoint-body">
                            <div class="endpoint-description">
                                <h3>{{ $endpoint['title'] }}</h3><a href="#{{ $endpoint['id'] }}" class="permalink"
                                    aria-label="Link to {{ $endpoint['title'] }}">#</a>
                                <p>{{ $endpoint['description'] }}</p>
                                <div class="endpoint-badges">
                                    <span>{{ $endpoint['auth'] ? 'Bearer token required' : 'Public · optional bearer token' }}</span><span>{{ $endpoint['contentType'] ?? 'No request body' }}</span>
                                    @if ($endpoint['rate'])
                                        <span>{{ $endpoint['rate'] }} requests / minute / IP</span>
                                    @endif
                                </div>
                            </div>
                            <div class="endpoint-columns">
                                <div class="contract">
                                    <h4>Parameters</h4>
                                    @if ($endpoint['parameters'])
                                        <div class="table-wrap">
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <th>Field / location</th>
                                                        <th>Type / rules</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($endpoint['parameters'] as $parameter)
                                                        <tr>
                                                            <td><code>{{ $parameter['name'] }}</code><small>{{ $parameter['in'] }}
                                                                    <span
                                                                        class="{{ $parameter['required'] ? 'required' : '' }}">·
                                                                        {{ $parameter['required'] ? 'required' : 'optional' }}</span></small>
                                                            </td>
                                                            <td><strong
                                                                    class="field-type">{{ $parameter['type'] }}</strong><small>{{ $parameter['description'] }}</small>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @else<p class="muted">No path, query or body parameters.</p>
                                    @endif
                                    <h4>Responses</h4>
                                    <p><span class="success-status">{{ $endpoint['status'] }}</span> Successful
                                        response shown alongside. Fields reflect the current resource contract.</p>
                                    @foreach ($endpoint['errors'] as $status => $description)
                                        <div class="error-row">
                                            <code>{{ $status }}</code><span>{{ $description }}</span>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="examples">
                                    <div class="code-panel request-example" data-id="{{ $endpoint['id'] }}">
                                        <div class="code-header"><label>Request <select class="language-select"
                                                    aria-label="Request example language">
                                                    <option value="curl">cURL</option>
                                                    <option value="javascript">JavaScript</option>
                                                </select></label><button type="button"
                                                data-copy-target="request-{{ $endpoint['id'] }}">Copy</button></div>
                                        <pre id="request-{{ $endpoint['id'] }}">{{ $endpoint['method'] }} {{ $endpoint['uri'] }}
Accept: application/json
@if ($endpoint['auth'])
Authorization: Bearer &lt;access-token&gt;
@endif
@if ($endpoint['example'])
{{ json_encode($endpoint['example'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}
@endif
</pre>
                                    </div>
                                    <div class="code-panel">
                                        <div class="code-header"><span>Response ·
                                                {{ $endpoint['status'] }}</span><button type="button"
                                                data-copy-target="response-{{ $endpoint['id'] }}">Copy</button></div>
                                        <pre id="response-{{ $endpoint['id'] }}">{{ json_encode($endpoint['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </div>
                                    @if (isset($endpoint['alternate']))
                                        <div class="code-panel">
                                            <div class="code-header">Alternative · multipart fallback · 200</div>
                                            <pre>{{ json_encode($endpoint['alternate'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </details>
                @endforeach
            </section>
        @endforeach
        <section id="schemas" class="guide-section">
            <div class="section-kicker">SHARED STRUCTURES</div>
            <h2>Response models</h2>
            <p>These are resource objects inside <code>data</code> (or each element of a list). Nested users use the
                User model. These examples include every field emitted by the relevant resource; optional loaded
                relationships are described below.</p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Model</th>
                            <th>Field behavior</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>User</td>
                            <td>name, bio and media URLs are nullable strings. following is a viewer-specific boolean.
                                cover_url and cover_video_url may be null.</td>
                        </tr>
                        <tr>
                            <td>Profile / MyProfile</td>
                            <td>Adds email, nullable email_verified_at and integer likes_count/videos_count; omits cover
                                fields. Only GET /auth/me loads social_identities: an array of linked identity records
                                (id, user_id, provider, provider_id, created_at).</td>
                        </tr>
                        <tr>
                            <td>Video</td>
                            <td>duration is nullable integer seconds. visibility is public, followers or private. status
                                is processing, published, failed or deleted (deleted resources are hidden). tags is a
                                string array extracted from caption. music falls back to “Original sound”. stats
                                contains integer counts; viewer contains booleans.</td>
                        </tr>
                        <tr>
                            <td>Video.edit</td>
                            <td>trim_start, trim_end and cover_time are nullable seconds; cut_points is a number array.
                                crop_mode is fit/fill, text_overlay is nullable text, original_audio_muted is boolean.
                                filter_settings and effect_settings are nullable JSON settings. Request fields are
                                top-level; response edit settings are nested.</td>
                        </tr>
                        <tr>
                            <td>Comment</td>
                            <td>parent_id is a nullable integer. reactions maps like/love/haha/wow/sad/angry to integer
                                counts. viewer_reaction is one of those strings or null. user is included when loaded
                                (current comment endpoints load it).</td>
                        </tr>
                        <tr>
                            <td>Notification</td>
                            <td>read_at is null until read. data contains actor (id, username, name, avatar URL or
                                null),
                                message, and nullable video_id, comment_id, parent_id, excerpt and reaction_type.
                                For replies, comment_id identifies the new reply and parent_id the original comment;
                                for reactions, comment_id identifies the reacted-to comment. excerpt is a short comment
                                snapshot. Actor details are snapshots; avatar URLs are resolved when fetched.
                                Render message and excerpt as plain text. Use actor.username for profile lookup and
                                video_id/comment_id for content navigation; deleted or inaccessible targets may return
                                404.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <h3>Notification events</h3>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Recipient / trigger</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>video_liked</td>
                            <td>Video owner, when a new like is created.</td>
                        </tr>
                        <tr>
                            <td>video_commented</td>
                            <td>Video owner, for a new comment or reply.</td>
                        </tr>
                        <tr>
                            <td>comment_replied</td>
                            <td>Parent comment author. If also the video owner, receives only this notice.</td>
                        </tr>
                        <tr>
                            <td>comment_reacted</td>
                            <td>Comment author, on the first reaction. Changing an existing reaction does not send
                                another notice.</td>
                        </tr>
                        <tr>
                            <td>user_followed</td>
                            <td>Followed user, when a new follow is created. Content fields are null.</td>
                        </tr>
                        <tr>
                            <td>video_shared</td>
                            <td>Video owner, on the first share by each signed-in, verified, active user.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p>Notifications are stored immediately with the action; no queue worker is required. Self-actions,
                blocked relationships, recipients without video access, saves, views, reports and anonymous shares
                do not generate notifications. Repeated active likes/follows/reactions do not create duplicates.
                Removing and adding a like, follow or reaction again creates a new event. Historical notifications
                remain after the underlying action is removed. Existing activity is not backfilled.</p>
            <p>Fetch <code>GET /api/v1/notifications?unread=1</code> for unread items. Use
                <code>meta.unread_count</code> for the badge, then mark individual items or all items read using the
                documented PATCH endpoints and refresh the count.
                <code>DELETE /api/v1/notifications/clear-all</code> permanently clears your read notifications;
                unread items are preserved. Delivery is through this inbox API; there are no
                email, push or WebSocket deliveries.
            </p>
            @foreach ($schemas as $name => $schema)
                @if (!in_array($name, ['ProcessingVideo', 'ReadNotification']))
                    <details class="schema">
                        <summary>{{ $name }} <span>object</span></summary>
                        <pre>{{ json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                @endif
            @endforeach
        </section>
        <section id="health" class="guide-section">
            <h2>Health check</h2>
            <p><span class="verb verb-get">GET</span> <code>/up</code> is outside the API version prefix and requires
                no authentication. Returns HTTP 200 with <code>{"status":"ok"}</code>. It confirms the application
                responds, not dependency readiness.</p>
        </section>
        <footer class="page-footer"><span class="brand">xspann <span class="muted">/ API reference</span></span><a
                href="#overview">Back to top ↑</a></footer>
    </main>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>
    <script id="reference-data" type="application/json">{!! json_encode(['baseUrl' => $baseUrl, 'groups' => $groups, 'schemas' => $schemas], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!}</script>
</body>

</html>
