# module-plan.md: Custom Module Implementation Plan

**Status:** Updated against the assessment brief, installed OpenID Connect source, and Beeceptor SOAP contract. Section 9 records implementation decisions.

## 1. Goal and scope

Build one small custom module, `sso_dashboards`, that sits on top of a contributed OIDC/Auth0 login module and provides:

1. Mapping of the Auth0 role claim to Drupal roles `member` / `exhibitor` at login.
2. Post-login redirect by role.
3. Two role-gated dashboard routes.
4. Server-side REST and SOAP clients whose results are rendered on the dashboards.

Out of scope: visual design, Auth0 user provisioning UI, any profile sync beyond what the contrib module already does.

## 2. Division of responsibility

| Concern | Owner |
| --- | --- |
| OIDC flow, token exchange, user creation/linking, logout | Contrib module (`openid_connect` with the Generic client. See `README.md` for setup.) |
| Put role in the ID token | Auth0 post-login **Action** (custom namespaced claim) |
| Claim to Drupal role sync | `sso_dashboards` |
| Redirect, routes, access, API calls, rendering | `sso_dashboards` |
| Drupal roles `member` / `exhibitor` | Optional config (`user.role.*.yml`) installed when the role IDs are absent |

`externalauth` is a transitive dependency of `openid_connect` for subject-to-user linking. The custom module uses OpenID Connect hooks directly.

Rationale: keep custom code minimal and avoid re-implementing OAuth. Custom code is only the project-specific glue.

## 3. Auth0 contract (input to the module)

- Roles are managed in Auth0 (RBAC roles assigned to users, or `app_metadata.roles`; decision in section 9).
- A post-login Action copies roles into the ID token as a namespaced claim, e.g. `https://sso-prototype.example/roles` (array of strings).
- The claim name is configurable in Drupal (`sso_dashboards.settings:role_claim`), not hard-coded.
- Read the custom claim from OpenID Connect’s verified `user_data` ID-token claims. The integration denies login through the configured client when the token is unverified.
- Auth0 plan limitations (free tier, Actions availability, trial-only features) are recorded in README.md.

## 4. Module structure

```
sso_dashboards/
  sso_dashboards.info.yml
  sso_dashboards.routing.yml
  sso_dashboards.services.yml
  config/install/
    sso_dashboards.settings.yml
  config/optional/
    user.role.member.yml
    user.role.exhibitor.yml
  config/schema/sso_dashboards.schema.yml
  src/
    Hook/LoginHooks.php
    Controller/DashboardController.php
    Service/RoleMapper.php
    Service/JsonPlaceholderClient.php
    Service/CountryInfoSoapClient.php
    Form/SettingsForm.php            (optional; settings via config is enough)
  tests/src/{Unit,Kernel,Functional}/...
```

Target: Drupal 11, PHP 8.3, `core_version_requirement: ^11`.

## 5. Components

### 5.1 Role mapping (`RoleMapper` + OpenID Connect hooks)

- `openid_connect_pre_authorize` requires verified ID-token claims for the Auth0 client. `openid_connect_post_authorize` synchronizes roles after OpenID Connect creates or loads the Drupal account and completes login.
- `RoleMapper::sync(UserInterface $account, array $claimRoles)`:
  - Allow-list: only the Drupal roles `member` and `exhibitor` are managed. Configure the Auth0 role names accepted for each Drupal role. Unmapped claim values are ignored.
  - Add managed roles present in the claim; **remove** managed roles absent from the claim (so revoking in Auth0 revokes in Drupal on next login). Never touch other roles (e.g. `administrator`).
  - Save only if the role set changed.
- No role in claim: user is logged in with no managed role and lands on a neutral page (see 5.2), and is denied both dashboards.
- Both roles in claim: allowed, but redirect priority is defined (default `exhibitor` > `member`, configurable). Access is still per-route.

### 5.2 Post-login redirect

- After login, send `member` to `/dashboard/member`, `exhibitor` to `/dashboard/exhibitor`, and role-less users to `/` with a short message.
- Set the redirect with OpenID Connect’s session destination API on successful Auth0 login.
- The local username-and-password form also redirects users with a dashboard role. Local accounts without a dashboard role keep the Drupal profile destination. Explicit local destinations take precedence.

### 5.3 Routes and access control

```yaml
sso_dashboards.member:
  path: '/dashboard/member'
  defaults: { _controller: '...DashboardController::member', _title: 'Member dashboard' }
  requirements: { _role: 'member' }

sso_dashboards.exhibitor:
  path: '/dashboard/exhibitor'
  defaults: { _controller: '...DashboardController::exhibitor', _title: 'Exhibitor dashboard' }
  requirements: { _role: 'exhibitor' }
```

- Core's `_role` requirement enforces access on every request including direct URLs; no custom access checker needed.
- Anonymous: HTTP 403.
- Wrong role: 403.
- Note: uid 1 / administrators are denied unless they hold the role. This is intentional ("only") and documented.

### 5.4 REST client (member dashboard)

- `JsonPlaceholderClient::getUser(int $id = 1): array` using Drupal's `http_client` service (Guzzle).
- `GET https://jsonplaceholder.typicode.com/users/1`, timeout 5s, `connect_timeout` 3s.
- Return a normalized array: `name`, `email`, `company.name`. Validate keys exist; otherwise throw a typed exception.
- Base URL in config, not hard-coded in the class.

### 5.5 Beeceptor SOAP client (exhibitor dashboard)

- **Approach:** build the SOAP 1.1 envelope by hand, `POST` via `http_client`, parse with `\DOMDocument`/`SimpleXMLElement`. Avoids a hard dependency on `ext-soap` and gives deterministic control over a mock service. Alternative (`\SoapClient` with the WSDL) noted in section 9.
- POST to the Beeceptor `/CountryInfoService.wso` endpoint using the WSDL’s `ListOfCountryNamesByName` operation, request namespace, and SOAPAction. Parse the original CountryInfo namespace used by Beeceptor’s live response.
- Parse with namespace-aware XPath; return list of `{name, isoCode}`.
- Disable external entity loading when parsing (no `LIBXML_NOENT`); treat the response as untrusted.
- Content-Type `text/xml; charset=utf-8`, same timeouts as REST.

### 5.6 Controller and rendering

- `DashboardController::member()` / `::exhibitor()` return render arrays: a heading, a definition list (member) or an `<ul>`/table (exhibitor). Plain text is enough.
- Show the Drupal username and role at the top for validation screenshots.
- Error handling: catch client exceptions, log via `logger.channel.sso_dashboards`, render a friendly "service unavailable" message with HTTP 200 (not a fatal error).
- Caching: share only validated public results with a 60-second Drupal cache lifetime. Render the current user identity on each request. Set `cache_max_age` to 0 to bypass the data cache.
- Escape all remote data (render via `#plain_text` / Twig autoescape).

## 6. Configuration and secrets

- No secrets in the repo. Auth0 domain, client ID and client secret are supplied via `settings.local.php` / environment variables and the contrib module's config override; the repo contains placeholders (`.env.example`, `settings.local.php.example`).
- Exported config in `config/sync` excludes secrets.
- `sso_dashboards.settings`: `client_id`, `role_claim`, `role_mapping`, `redirect_priority`, `rest_base_url`, `soap_endpoint`, `cache_max_age`.

## 7. Testing and validation

**Automated**
- Unit: `RoleMapper` (add/remove/ignore unknown/idempotent), REST and SOAP response parsing using fixture payloads (including malformed/missing fields).
- Kernel: routing access (`_role`) for anonymous / member / exhibitor / both.
- Functional: dashboard pages render with HTTP client mocked.

**Manual (README.md validation table, with screenshots)**

| # | Check | Expected |
| --- | --- | --- |
| 1 | Member logs in via Auth0 | Drupal user has `member`; lands on `/dashboard/member` |
| 2 | Exhibitor logs in | Has `exhibitor`; lands on `/dashboard/exhibitor` |
| 3 | Member requests `/dashboard/exhibitor` directly | 403 |
| 4 | Exhibitor requests `/dashboard/member` directly | 403 |
| 5 | Anonymous requests either route | Denied |
| 6 | Member page | Name, email, company from live JSONPlaceholder call |
| 7 | Exhibitor page | Country names and ISO codes from live SOAP call |
| 8 | Logout | Drupal and Auth0 session ended; dashboards denied afterwards |
| 9 | Revoke role in Auth0, log in again | Drupal role removed |
| 10 | Upstream down (simulate) | Friendly error, no fatal |

## 8. Delivery milestones

1. Environment: Drupal 11, Auth0 tenant, two test users and roles, contrib module installed and login working.
2. Claim: Action adds roles claim; confirm it reaches Drupal (log only the claim keys, never tokens).
3. Module skeleton, roles, routes and access; redirect.
4. `RoleMapper`, OpenID Connect hooks, and logout.
5. REST client and member dashboard.
6. SOAP client and exhibitor dashboard.
7. Tests, validation run, screenshots, docs.

## 9. Implementation decisions

1. **Contrib module:** `openid_connect` Generic client, pinned to the 3.x revision that supports strict ID-token verification.
2. **Role source in Auth0:** Assigned role names read from `event.authorization.roles` and copied to a namespaced ID-token claim in a Post Login Action. Administrator-managed `app_metadata.roles` is an alternative when role assignments are unavailable.
3. **Anonymous behavior:** HTTP 403.
4. **SOAP method:** SOAP 1.1 envelope over Drupal’s Guzzle client, with namespace-aware DOM parsing.
5. **Dual-role users:** Both routes are available. Default redirect priority is exhibitor, then member.
6. **Administrators:** Dashboard access still requires the specific dashboard role.
7. **Caching:** 60 seconds for shared upstream data by default. A zero TTL bypasses the data cache for demonstrations.
