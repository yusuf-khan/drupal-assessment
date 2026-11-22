# Drupal Auth0 SSO Assessment

A Drupal 11 prototype with Auth0 sign-in, role-based dashboard access, and
server-side REST and SOAP integrations. See the [module plan](module-plan.md)
and [AI development log](agent-logs.md).

## Architecture

```text
Drupal login page
  |
  v
Auth0 Universal Login
  |
  v
Auth0 Post Login Action adds roles to the ID token
  |
  v
Drupal verifies the token and creates or loads the local account
  |
  v
Drupal synchronizes roles and chooses the destination
  |
  +-- member ----> /dashboard/member ----> JSONPlaceholder REST
  |
  +-- exhibitor -> /dashboard/exhibitor -> Beeceptor SOAP
  |
  +-- no dashboard role -> Home with a status message
```

Drupal checks the required role on every dashboard request, including direct
visits. It caches validated public API results for 60 seconds and renders the
current user's identity on each response. The APIs use HTTPS and require no
credentials.

## Environment and dependencies

Install Git, Docker, and [DDEV](https://ddev.com/get-started/).

| Component | Version |
| --- | --- |
| Drupal core | 11.4.13 |
| PHP and database in DDEV | PHP 8.4 and MariaDB 11.8 |
| Drush | 13.8.1 |
| OpenID Connect | 3.x at commit `acc27bd` |
| External Auth | 2.0.15, installed through OpenID Connect |

Composer restores the exact dependency versions from `composer.lock`.

## Setup and run

```sh
git clone https://github.com/yusuf-khan/drupal-assessment.git
cd drupal-assessment
ddev start
ddev composer install
ddev drush site:install --existing-config --db-url=mysql://db:db@db/db --account-name=admin -y
ddev launch
```

Run the install command on a new DDEV database. Drupal installs the modules,
the Auth0 client, and both dashboard roles from `config/sync`. Drush prints the
local administrator credentials. Use `ddev describe` to confirm the site URL,
which defaults to `https://drupal-assessment.ddev.site`.

### Configuration synchronization

Drupal reads `config/sync` from the repository root. On a site installed from
this export, run `ddev drush config:status` to see differences and
`ddev drush config:import` to review and import changes. The same screen is
available at **Configuration → Development → Configuration synchronization**
(`/admin/config/development/configuration`).

Drupal requires a matching site UUID to import the full export. An
independently installed site can continue using its current configuration.
Before exporting active configuration, verify that any Auth0 credentials are
supplied through the local settings file.

## Module evaluation

- **OpenID Connect** powers the current implementation. Its Generic client
  supports Auth0, account provisioning, login hooks, and provider logout. The
  pinned revision supports strict ID-token validation for role assignments.
- **Drupal OIDC Client** was evaluated for its signed-token validation and
  realm management. OpenID Connect’s authorization hooks and login destination
  API fit the dashboard flow.
- **miniOrange SAML SP** is the target for a separate pilot. Its Composer
  edition passed an isolated Drupal 11 install check. The assessment install
  uses OpenID Connect. Mapping assertion roles per user and SAML Single Logout
  require the licensed miniOrange edition.
  [miniOrange plan comparison](https://plugins.miniorange.com/drupal-saml-single-sign-on-sso#pricing)

## Auth0 and test users

1. Create an Auth0 **Regular Web Application** with a database connection,
   Authorization Code grant, and RS256 signing.
2. Add `https://drupal-assessment.ddev.site/openid-connect/auth0` to **Allowed
   Callback URLs** and `https://drupal-assessment.ddev.site/user/login` to
   **Allowed Logout URLs**. Use your site's origin if it differs. Enable
   RP-Initiated Logout discovery if the tenant omits `end_session_endpoint`.
3. Create two test users with distinct email addresses. Assign `member` to
   one and `exhibitor` to the other. Use emails that do not already belong to
   local Drupal accounts.
4. Create an Auth0 **Post Login** Action from
   [post-login-roles.js](auth0/post-login-roles.js). Replace the client ID
   placeholder, click **Deploy**, attach the Action under **Actions →
   Triggers → Post Login**, and click **Apply**.

If your Auth0 plan does not expose role assignments, use administrator-managed
`app_metadata` with `{"roles":["member"]}` and `{"roles":["exhibitor"]}`.
Set the Action's role source to `const roles = event.user.app_metadata?.roles;`.
Both sources use the same Drupal mapping. [Auth0 plans](https://auth0.com/pricing)

The configuration export includes the Auth0 OpenID Connect client.
`web/sites/default/settings.secret.php` supplies the public Client ID for the
bundled Auth0 application. Copy the [local settings template](settings.local.php.example)
from the repository root:

```sh
cp -n settings.local.php.example web/sites/default/settings.local.php
```

Open `web/sites/default/settings.local.php`, replace the placeholder with the
Auth0 Client Secret, and run `ddev drush cr`. Git ignores this local file.
If you use a different Auth0 application, set the Client ID in the same local
file with `$config['openid_connect.client.auth0']['settings']['client_id']`.
For a different Auth0 tenant, also update the issuer and endpoints at
`/admin/config/people/openid-connect`.

If credentials were previously saved in active Drupal configuration, populate
the local file first and then clear the saved values before exporting.

```sh
ddev drush php:eval '$config = \Drupal::configFactory()->getEditable("openid_connect.client.auth0"); $config->clear("settings.client_id")->clear("settings.client_secret")->save();'
ddev drush cr
```

Open `/user/login` in a private window and choose **Auth0** below the local
form. Sign in as each user in turn. Visit the other role’s route directly
and verify HTTP 403. Use **Log out** to end the Drupal and Auth0 sessions.

## Role mapping and dashboards

External Auth links the Auth0 subject to its Drupal account. After each
successful login, the dashboard module reads the verified
`https://sso-prototype.example/roles` ID-token claim. It adds matching
dashboard roles and removes managed roles that are no longer assigned.
Unrelated Drupal roles are preserved.

| Drupal role | Destination | Live data |
| --- | --- | --- |
| `member` | `/dashboard/member` | JSONPlaceholder sample user's name, email, and company |
| `exhibitor` | `/dashboard/exhibitor` | Beeceptor SOAP country names and ISO codes |

With both roles, the default destination is the exhibitor dashboard.
Without a dashboard role, Drupal redirects home with a status message.
Configure role aliases, priority, or cache lifetime in
[`sso_dashboards.settings`](web/modules/custom/sso_dashboards/config/install/sso_dashboards.settings.yml).
For example, accept both `member` and `Customer` from Auth0 with:

```sh
ddev drush config:set sso_dashboards.settings role_mapping.member '[member, Customer]' --input-format=yaml -y
```

### Preview without Auth0

Run these commands once to create local demo accounts and print one-time links.
Open the two links in separate browser sessions.

```sh
ddev drush user:create assessment_member --mail=member@example.test
ddev drush user:role:add member assessment_member
ddev drush user:create assessment_exhibitor --mail=exhibitor@example.test
ddev drush user:role:add exhibitor assessment_exhibitor
ddev drush user:login --name=assessment_member --no-browser dashboard/member
ddev drush user:login --name=assessment_exhibitor --no-browser dashboard/exhibitor
```

## Validation results

The DDEV suite passed **12 tests with 86 assertions**. The browser test
installs a fresh Drupal Standard site and enables the assessment module.
The tests cover account provisioning, role reconciliation, redirects,
access checks, caching, and API failure handling with fixtures. PHPUnit
reported four deprecations from External Auth and its Views configuration.

```sh
ddev exec env SIMPLETEST_BASE_URL=http://localhost \
  SIMPLETEST_DB=mysql://db:db@db/db BROWSERTEST_OUTPUT_DIRECTORY=/tmp \
  vendor/bin/phpunit -c web/core web/modules/custom/sso_dashboards/tests/src
```

| Check | Result |
| --- | --- |
| Live Auth0 member | Assigned `member`, redirected to the member dashboard, HTTP 200 for member and 403 for exhibitor |
| Live Auth0 exhibitor | Assigned `exhibitor`, redirected to the exhibitor dashboard, HTTP 200 for exhibitor and 403 for member |
| Live Auth0 logout | Both accounts returned to Drupal after Auth0 logout. Both dashboards returned 403 in each logged-out session. |
| Live APIs | REST returned Leanne Graham’s sample record. SOAP returned 246 countries. |
| Direct URL access | Browser tests verified 403 for wrong-role and anonymous requests. |

The screenshots use local Drupal demo accounts and live API data.
[Member dashboard](screenshots/member-dashboard.png) ·
[Exhibitor dashboard](screenshots/exhibitor-dashboard.png) ·
[Member denied exhibitor](screenshots/member-exhibitor-denied.png) ·
[Exhibitor denied member](screenshots/exhibitor-member-denied.png) ·
[Anonymous denied](screenshots/anonymous-member-denied.png)

## Challenges and blockers

- **Missing roles after sign-in.** The Auth0 Action was deployed but missing
  from the Post Login flow. Applying the binding restored the role claim.
- **Local database recovery.** An earlier prototype left stale module
  registrations and a missing External Auth table. The local database was
  repaired. Fresh installs create the required schema automatically.
- **Pending live check.** Role revocation is covered by the automated
  login test. Live revocation has yet to be verified.
- **SAML cutover.** The miniOrange target requires the licensed edition and
  a separate Auth0 SAML application. The live prototype uses OpenID Connect.
  The [SAML Post Login Action](auth0/post-login-saml-roles.js) and
  [cutover plan](module-plan.md#10-miniorange-saml-pilot-with-auth0) are
  available for that evaluation.
