# Drupal Assessment

This Drupal 11 assessment uses Auth0 sign-in to assign Drupal roles and
open one of two role-gated dashboards. The member dashboard reads a sample
user from JSONPlaceholder. The exhibitor dashboard reads country names and
ISO codes from the Beeceptor Country Information SOAP mock. See the
[reviewed module plan](module-plan.md) and [AI development log](agent-logs.md).

## Run locally

Install Git, Docker, and [DDEV](https://ddev.com/get-started/). The included
DDEV configuration uses PHP 8.4 and MariaDB 11.8. The project uses Drupal 11.4
and Drush 13.8 or later in the 13.x series. Exact Composer dependency versions
are recorded in `composer.lock`.

From the root of your clone, run:

```sh
ddev start
ddev composer install
ddev launch
```

On a fresh installation, complete Drupal's browser installer using the
**Standard** profile and create a local administrator. Then enable the module:

```sh
ddev drush en sso_dashboards -y
```

The default site URL is `https://drupal-assessment.ddev.site`. Use
`ddev describe` to check the URL and `ddev drush status` to check the Drupal
installation. Composer restores Drupal and its dependencies from
`composer.lock`. The repository includes the custom module, DDEV
configuration, and test fixtures. Local credentials and the database are
created separately for each installation.

## Preview the dashboards without Auth0

The automated suite below exercises both dashboards with fixture data and
requires only DDEV. To try the pages in a browser with the configured live
endpoints, create two local demo accounts on your fresh installation:

```sh
ddev drush user:create assessment_member --mail=member@example.test
ddev drush user:role:add member assessment_member
ddev drush user:create assessment_exhibitor --mail=exhibitor@example.test
ddev drush user:role:add exhibitor assessment_exhibitor
```

Generate a one-time sign-in link for each account in turn:

```sh
ddev drush user:login --name=assessment_member
ddev drush user:login --name=assessment_exhibitor
```

Open each link in a separate browser session. Visit `/dashboard/member` as
the member and `/dashboard/exhibitor` as the exhibitor. The other dashboard
should return HTTP 403.

The standard Drupal username-and-password form at `/user/login` also
redirects local member and exhibitor accounts to their dashboards. Local
accounts without a dashboard role keep Drupal’s default destination. An
explicit local `destination` takes precedence. This preview tests local roles,
route access, and rendering. The Auth0 steps below exercise the login
redirect, claim synchronization, and single sign-out.

## Auth0 integration

The module uses [Drupal OpenID Connect](https://www.drupal.org/project/openid_connect)
with the Generic client. Composer pins revision `acc27bd` of the 3.x branch
because it includes strict ID-token verification. OpenID Connect brings in
External Auth transitively to associate Auth0's `sub` with a Drupal user in
`authmap`. On first sign-in, OpenID Connect creates the Drupal account. On
later sign-ins, it loads the same account by OpenID Connect client and subject. The
custom module uses OpenID Connect hooks and declares only `openid_connect`
as a module dependency. It reads roles from verified ID-token claims.
Drupal creates the dashboard roles if their IDs are absent.

The alternative Drupal OIDC Client module offered signed-token validation and
realm management. OpenID Connect was chosen for its documented authorization
hooks and configurable login destination. Its released 3.x alpha lacked the
strict token verification needed to trust role grants, which is why the
prototype pins a development revision. The custom module implements role
reconciliation, dashboard selection, route access, and API rendering.

### Configure Auth0

1. Create a **Regular Web Application** in Auth0. Use Universal Login, an Auth0
   database connection, RS256 signing, and the Authorization Code grant.
2. Add `https://drupal-assessment.ddev.site/openid-connect/auth0` to Allowed
   Callback URLs. Add
   `https://drupal-assessment.ddev.site/user/login` to Allowed Logout
   URLs. Use the actual Drupal origin if it differs. Enable RP-Initiated Logout
   endpoint discovery if the tenant does not publish `end_session_endpoint`.
   [Auth0 OIDC logout settings](https://auth0.com/docs/authenticate/login/logout/log-users-out-of-auth0)
3. Create two test users. If Auth0 exposes role assignments in your tenant,
   create the `member` and `exhibitor` roles under **User Management → Roles**
   and assign one role to each test user from their **Roles** tab.
4. Create an **Add Drupal Roles** Action for the Post Login trigger. Use the
   [Auth0 Roles Action](auth0/post-login-roles.js) and replace the client ID
   placeholder with the Drupal application's client ID. Click **Deploy**.
   Under **Actions → Triggers → Post Login**, place the Action between
   **Start** and **Complete**, then click **Apply**. Both steps matter. An
   empty flow issues the ID token without the role claim.

If your plan does not expose role assignments, store the role names in
administrator-managed `app_metadata` instead. Set
`{"roles":["member"]}` and `{"roles":["exhibitor"]}` on the two accounts.
Change the Action's role source line to
`const roles = event.user.app_metadata?.roles;`. The claim and Drupal
mapping use the same contract. This approach uses one Action and avoids
depending on trial-only RBAC features. Check the subscription for its
current Actions and RBAC entitlements.
[Auth0 plan comparison](https://auth0.com/pricing) ·
[Auth0 role claim documentation](https://support.auth0.com/center/s/article/add-roles-and-permissions-to-the-id-token-using-actions)

After a change, sign out and start a new Auth0 login. If Drupal only shows
the **Authenticated user** role, check the Action binding, the exact claim
name, and the configured role names before retrying.

### Configure Drupal

1. Run `cp settings.local.php.example web/sites/default/settings.local.php`
   on a fresh clone. If you already have a local settings file, copy the
   relevant overrides into it. Uncomment the local settings include
   at the end of `web/sites/default/settings.php`. Set the three variables from
   `.env.example` in the PHP process environment. For a local DDEV demo, you can
   instead replace the three empty fallback values in the ignored
   `settings.local.php` with the real values. Keep actual credentials in the
   environment or that ignored file. The realm config exported from Drupal
   contains no client secret when credentials are supplied via these overrides.
2. Create the enabled Generic client on a fresh installation and configure
   first-login provisioning:

   ```sh
   ddev drush php:eval '\Drupal\openid_connect\Entity\OpenIDConnectClientEntity::create(["id" => "auth0", "label" => "Auth0", "plugin" => "generic", "status" => TRUE])->save();'
   ddev drush config:set user.settings register admin_only -y
   ddev drush config:set openid_connect.settings override_registration_settings true --input-format=yaml -y
   ddev drush config:set openid_connect.settings connect_existing_users false --input-format=yaml -y
   ddev drush config:set openid_connect.settings end_session_enabled true --input-format=yaml -y
   ddev drush config:set openid_connect.settings redirect_logout /user/login -y
   ddev drush cr
   ```

   The registration override lets OpenID Connect create accounts on first
   login while Drupal's local registration stays administrator-only. Use
   Auth0 test email addresses that are distinct from existing Drupal email
   addresses. Subsequent sign-ins use the same linked account. OpenID Connect's
   strict verification checks the token before its claims are used for login.
3. Visit `/user/login/openid_connect` and choose Auth0. To end both sessions,
   use Drupal's **Log out** link. The local administrator can sign in at
   `/user/login`.

Both dashboard routes enforce the corresponding Drupal role, including for
administrators and uid 1. Anonymous and wrong-role requests receive HTTP 403
A dual-role user can open both dashboards
and lands on the exhibitor dashboard by default. An empty or absent claim
clears both managed roles and redirects home. To change the claim or role
priority, update `sso_dashboards.settings`. Drupal shares successful public API results between dashboard users for
60 seconds. Each response renders the signed-in identity from the current
account. Endpoint and cache configuration changes invalidate the stored
results. The exhibitor table is included in the initial HTML. A cold cache
can wait for the upstream SOAP response.

Set `cache_max_age` to `0` to bypass the data cache during validation.

```sh
ddev drush config:set sso_dashboards.settings cache_max_age 0 -y
```

Auth0 role names can differ from Drupal’s two dashboard role IDs. To map
additional names, configure each Drupal dashboard role’s list of accepted
Auth0 names. Matching is case-sensitive. For example:

```sh
ddev drush config:set sso_dashboards.settings role_mapping.member '[member, Customer]' --input-format=yaml -y
ddev drush config:set sso_dashboards.settings role_mapping.exhibitor '[exhibitor, Trade Partner]' --input-format=yaml -y
```

Run `ddev drush updb -y` on existing installations. If you use custom Auth0 role
names, redeploy the updated Action so it includes those names in the verified
ID-token claim. The mapping is applied on the next Auth0 login.

The exhibitor client sends SOAP 1.1 `ListOfCountryNamesByName` to Beeceptor
with the action and request namespace declared by its WSDL. The mock response
uses the original CountryInfo namespace, which the parser handles explicitly.
Drupal calls both public APIs server-side over HTTPS without API keys. The
clients reject redirects and unexpected response statuses. The member record
is fictional sample data from JSONPlaceholder.
[Beeceptor SOAP documentation](https://app.beeceptor.com/virtual-sandbox/soap-service-free) ·
[Country Information WSDL](https://soap-service-free.mock.beeceptor.com/CountryInfoService?WSDL)

## Verification

Run the unit, kernel, and browser tests in DDEV. The tests use isolated database
tables and HTTP fixtures. The DDEV run passed 12 tests with 85 assertions.
The login kernel test exercises account creation and reuse with a mocked
ID-token validator.

```sh
ddev exec env SIMPLETEST_BASE_URL=http://localhost \
  SIMPLETEST_DB=mysql://db:db@db/db BROWSERTEST_OUTPUT_DIRECTORY=/tmp \
  vendor/bin/phpunit -c web/core web/modules/custom/sso_dashboards/tests/src
```

| Check | Expected result | Evidence |
| --- | --- | --- |
| First and subsequent sign-ins | First sign-in creates the Drupal account. Subsequent sign-ins reuse it | Live Auth0 account creation confirmed. Kernel test covers repeated sign-in |
| Member signs in with Auth0 | Drupal assigns `member` and opens `/dashboard/member` | Verified with the live Auth0 tenant. Member dashboard returned HTTP 200 and exhibitor dashboard returned HTTP 403 |
| Exhibitor signs in with Auth0 | Drupal assigns `exhibitor` and opens `/dashboard/exhibitor` | Auth0-linked Drupal account has `exhibitor`. A fresh exhibitor sign-in and redirect replay is pending |
| Member opens exhibitor URL | HTTP 403 | Browser test |
| Exhibitor opens member URL | HTTP 403 | Browser test |
| Anonymous opens either URL | HTTP 403 | Browser test |
| Member dashboard | Shows the sample user's name, email and company | Browser test with REST fixture. The live Drupal client returned Leanne Graham, Sincere@april.biz, and Romaguera-Crona |
| Exhibitor dashboard | Shows country names and ISO codes | Browser test with SOAP fixture. The live Drupal client returned 246 entries, starting with Åland Islands (AX) |
| Auth0 logout | Both sessions end and dashboards return 403 | Live member test followed the Auth0 logout endpoint, returned to Drupal, and received 403 on both dashboards. Auth0 prompted for credentials on the next sign-in |
| Auth0 role revoked | Next login removes the Drupal role | Kernel test with mocked ID-token validator. Live tenant validation pending |
| Upstream outage | Dashboard returns HTTP 200 with a service-unavailable message | Browser test with simulated HTTP 503 |

### Screenshot evidence

These screenshots use local Drupal demo accounts and live upstream API data.
The browser also checked HTTP 403 for both dashboard URLs while anonymous.

- [Member dashboard with live REST data](screenshots/member-dashboard.png)
- [Exhibitor dashboard with live SOAP data](screenshots/exhibitor-dashboard.png)
- [Member denied exhibitor dashboard](screenshots/member-exhibitor-denied.png)
- [Exhibitor denied member dashboard](screenshots/exhibitor-member-denied.png)
- [Anonymous visitor denied member dashboard](screenshots/anonymous-member-denied.png)

Live Auth0 account creation, member role mapping, redirect, access control,
and logout were verified. A fresh exhibitor sign-in and logout replay and
live role revocation remain in the validation checklist.

## Submission notes

A local DDEV database previously retained module registrations from an earlier
prototype. The registry was backed up and repaired. A fresh installation uses
the module's declared dependencies and needs no database import. An existing
installation of this custom module can run `ddev drush updb -y` to migrate the
original default SOAP URL to Beeceptor. A customized SOAP URL is preserved.

Before sharing the repository, complete the live Auth0 checks in the table
with the two Auth0 accounts. Share the repository URL, submission branch, and
the output of `git rev-parse HEAD` with the interview team.
