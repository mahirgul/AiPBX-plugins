# LDAP / Active Directory contacts

Reads the people of your company directory (Active Directory, OpenLDAP) into the AiPBX
phonebook (**Phonebook** page). Desk phones that use the AiPBX LDAP phonebook then find them by name
and show who is calling. Nothing is ever written to your directory.

Requires AiPBX 1.77.1 or newer (the shared phonebook, its LDAP server and `php-ldap`).

## Setup

1. **Integrations → Plugins**: install **LDAP / Active Directory contacts** and open it.
2. Fill in the server, the connection (LDAPS recommended), a read-only user, the search base and
   the filter. For Active Directory the user can be written as `reader@example.local`.
3. **Test the connection**: shows how many people with a number were found, without changing anything.
4. **Read now**, or wait for the interval (default 60 minutes).

## How it works

- Each read takes every matching entry (in pages of 500, so large directories work), maps the
  attributes in the settings and keeps those with a name and at least one number.
- New people are added, changed ones updated, people who left the directory removed, all under the
  source `ldap-sync`. Contacts added by hand on the Phonebook page are never touched.
- When the directory answers with nobody (a wrong base or filter), the phonebook is left as it is.
- The certificate of the directory is checked unless you turn that off for a test server.

## Tests

```bash
php plugins/community/ldap-sync/tests/ldap_sync_test.php
```

## Changes

### 1.0.0

- First version.
