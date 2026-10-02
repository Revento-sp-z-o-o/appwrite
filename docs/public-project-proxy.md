# Single-project public API proxy

A private multi-project installation can expose one project through a reverse
proxy. `X-Appwrite-Public-Project` restricts the final resolved project and
forbids Console/admin mode in both HTTP and realtime. It never grants access;
normal project permissions, authentication and origin checks still apply.
GraphQL subrequests clone the original request, retaining this restriction.

The public proxy MUST overwrite this header with the selected project ID on
every forwarded request. Also set `X-Appwrite-Project` as the default project
(for preflight and realtime); body/query/legacy path overrides are still
checked after resolution. Never derive the scope from client input. A scope
of `console` is forbidden. Missing scope preserves private API behavior.
The upstream must be reachable only by trusted proxies/private clients; direct
public access would bypass this opt-in restriction. Private proxies should
strip client-supplied scope headers.

This is an API context restriction, not a complete public ingress policy.
The proxy must expose only intended client API routes, deny Console/static
administration and platform control routes, and route public Sites/Functions
separately. Do not proxy arbitrary hosts into the custom-domain deployment
router. Validate client request formats, GraphQL, websocket upgrades, upload,
download, CORS and negative cross-project/admin cases before opening ingress.
