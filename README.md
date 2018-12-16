# LDAP users and groups sync script for Gitlab-CE

This nifty little PHP-CLI tool will synchronise users and user groups from an LDAP server to Gitlab community edition instance(s).

Though this functionality is available out of the box with Gitlab enterprise edition the pricing model is completely infeasible for teams of hobbyists working on non-revenue based projects but need to use a centralised authentication base.

As a bonus it can also do a light rake of LDAP users not currently in Gitlab, so those that haven't signed in for their first time can still have projects and permissions assigned to them. **This may make the tool unsuitable git Gitlab-EE as this would certainly impact its licensing fees!**

## **THIS TOOL IS NOT COMPLETED YET. DO NOT USE IT IN A PRODUCTION ENVIRONMENT.**

**Seriously. Only use this on test Gitlab CE instances.**

What is complete:

* Reading users from LDAP
* Reading groups from LDAP
* Synchronising groups to Gitlab

What is left to-do:

* Synchronising users to Gitlab
* Synchronising group memberships to Gitlab

**For now always use the dry run `-d` option to prevent writing to Gitlab. You have been warned.**

## Getting Started

These instructions will get you a copy of the project up and running on your local machine for development, testing, and live purposes.

### Prerequisites

Requirements for running this tool from a management station:

* Any system that can run PHP-CLI will do. (Even Windows.)
* [PHP](https://www.php.net) version 7.0 or later: Available to most Linux distributions via `apt-get` or `yum`. You don't need anything web related, but you will need the command line interface.
* [Composer](https://getcomposer.org/): Available to most Linux distributions via `apt-get` or `yum`, or manually download it as `composer.phar` alongside this tool.
* LDAP instance: Used for Gitlab's authentication. It can (likely) be Microsoft Active Directory, OpenLDAP, 389-DS (including FreeIPA), and likely any other LDAP system, though **most of my testing is using a 389-DS instance**.
* [Gitlab community edition](https://gitlab.com/gitlab-org/gitlab-ce/): This must be configured to authenticate against an LDAP instance already.

## Installing

Either checkout this project or download it in ZIP form and extract it somewhere safe. The configuration will later contain an LDAP password and Gitlab API secret keys, so do put some protection in place to ensure only you can access it.

After this you will need to install PHP components delivered via [Composer](https://getcomposer.org/). To do this open a terminal and change the working directory to this tool's location.

* If you have Composer installed as a system-wide application (e.g. via `apt-get` or `yum`) use command `composer install`.
* If you have Composer manually downloaded residing as `composer.phar` alongside this tool use command `php composer.phar install`.

## Configuration

Make a duplicate copy of `config.yml.dist` called `config.yml`, then open `config.yml` in your favourite text editor. You may be able to work out how to configure this quite easily yourself, but still here's an explanation.

### ldap

This section configures how to communicate with your LDAP instance.

#### debug *(bool|null)*

Enable this to show debugging information regarding LDAP connectivity. This is useful for detecting issues such as SSL certificate verification failures.

Default: *false*

#### server

This sub-section configures how to connect to your LDAP server. Most of this follows [Symfony's LDAP component](https://symfony.com/doc/current/components/ldap.html).

##### host *(string)*

IP or hostname of the LDAP server. You should use "localhost" if the you're running this tool on the same machine as the LDAP server.

##### port *(int\null)*

Port used to access the LDAP server. Typically 389 for unencrypted connections or STARTTLS encrypted connections, but 636 for implicit SSL/TLS connections.

Leaving this null will use the default port based on the **encryption** setting.

##### version *(int|null)*

The version of the LDAP protocol to use. Typically 3 these days.

Leaving this null will assume version 3.

##### encryption *(string|null)*

The encryption protocol.

* "none" for unencrypted connections, usually port 389. (Generally only safe to use with "localhost" or a very tightly controlled link between this tool and the LDAP server.)
* "tls" for **explicit** SSL/TLS connections, usually on port 389. (Often called "STARTTLS".)
* "ssl" for **implicit** SSL/TLS connections, usually on port 636. (Often called "LDAPS".)

Leaving this null will assume an unencrypted connection.

##### bindDn *(string|null)*

If your LDAP server does not allow anonymous access (which is a sensible restriction) specify a **full distinguished name**. (You cannot just specify the user name on its own.)

For example: "uid=Administrator,ou=People,dc=example,dc=com"

##### bindPw *(string|null)*

If your LDAP server does not allow anonymous access (which is a sensible restriction) specify the password to go with the bind distinguished name.

Because this has to be stored in the tool's configuration in plain text it would be advisable to create a separate user with limited read-only access to your directory. **Do not use a bind DN/password with administrative/root permissions!**

#### queries

This sub-section configures what to look for in your LDAP instance.

##### baseDn *(string)*

Specify the base distinguished name to work with. This will also be appended to all further DN settings.

Example to work with entire domain name: "dc=example,dc=com"
Example to work with a specific organisational unit tree: "ou=Internal,dc=example,dc=com"

##### userDn *(string|null)*

Specify the distinguished name containing user objects to be searched for.

* For Microsoft Active Directory this is typically "CN=Users".
* For OpenLDAP and 389-DS this is typically "ou=People".

Leaving this null will search the entire base DN.

Default: *null*

##### userFilter *(string|null)*

Specify a search filter for finding user objects within the above DN.

* For Microsoft Active Directory this is typically "(objectClass=user)".
* For OpenLDAP and 389-DS this is typically "(objectClass=inetOrgPerson)".

Default: "(objectClass=inetOrgPerson)"

##### userUniqueAttribute *(string|null)*

Specify the attribute used to uniquely identify a user by their user name. Their values must be a simple name of which the user would typically type to login to Gitlab or any other application interfacing with the same directory.

Default: "uid"

##### userNameAttribute *(string|null)*

Specify the attribute used for the user's full real name.

Default: "cn"

##### userEmailAttribute *(string|null)*

Specify the attribute used for the user's email address. (If there are multiple values only the first will be used.)

Default: "mail"

##### groupDn *(string|null)*

Specify the distinguished name containing group objects to be searched for.

* For Microsoft Active Directory this is typically "CN=Users".
* For OpenLDAP this is typically "ou=Group".
* For 389-DS this is typically "ou=Groups".

Leaving this null will search the entire base DN.

Default: *null*

##### groupFilter *(string|null)*

Specify a search filter for finding group objects within the above DN.

* For Microsoft Active Directory this is typically "(objectClass=group)".
* For OpenLDAP this is typically "(objectClass=posixGroup)"
* For 389-DS this is typically "(objectClass=groupOfUniqueNames)".

Default: "(objectClass=groupOfUniqueNames)"

##### groupUniqueAttribute *(string|null)*

Specify the attribute used to uniquely identify a group by its name.

Default: "cn"

##### groupMemberAttribute *(string|null)*

Specify the attribute for group objects defining which users are a member of it. The values must be user names in their simple form matching the values you'd get with "usersUniqueAttribute", and not containing any structural information such as full distinguished names of users.

This is typically "memberUid" for Microsoft Active Directory, OpenLDAP, and 389-DS.

Default: "memberUid"

### gitlab

This section configures how to communicate with your Gitlab-CE instance.

#### options

##### userNamesToIgnore *(array|null)*

Specify a list of user names of which this tool should ignore. (Case-sensitive.)

This varies not only according to which directory software you're using, but also how your directory has been structured.

* For Microsoft Active Directory this is could be "Administrator", "Guest", and any other user you don't expect to contain human users.
* OpenLDAP and 389-DS do not ship with any users out of the box, though "root" and "nobody" are likely candidates to ignore.

This must be defined as an array even if you have only 1 user. Be sure to quote user names that have spaces. For example:

```
userNamesToIgnore:
    - "root"
    - "nobody"
    - "Administrator"
    - "Guest"
```

User name "root" must always be ignored because this is the built-in Gitlab root user. Do not attempt to create/sync this user name.

Default: *null*

##### groupNamesToIgnore *(array|null)*

Specify a list of group names of which this tool should ignore. (Case-sensitive.)

This varies not only according to which directory software you're using, but also how your directory has been structured. You do not have to specify every group if you've left the "createEmptyGroups" setting (further down) switched off, as this will prevent groups containing no users to be ignored anyway.

* For Microsoft Active Directory this is could be "Domain Computers", "Domain Controllers", "DnsAdmins", "DnsUpdateProxy", and any other group you don't expect to contain human users.
* OpenLDAP and 389-DS do not ship with any groups out of the box.

This must be defined as an array even if you have only 1 group. Be sure to quote group names that have spaces. For example:

```
groupNamesToIgnore:
    - "Root"
    - "Users"
    - "Managed Service Accounts"
    - "Marketing Staff"
```

Group names "Root" and "Users" must always be ignored because they are reserved keywords. Do not attempt to create/sync these group names.

Default: *null*

##### createEmptyGroups *(bool|null)*

Specify whether groups containing no LDAP users should still be created in Gitlab.

You should enable this if you want to specify permissions for groups in advance, so they'll be ready when the first user is added to that group. If your directory has a lot of empty groups enabling this would only replicate the clutter to Gitlab.

Default: *false*

##### deleteExtraGroups *(bool|null)*

Specify whether Gitlab groups not found in LDAP should be deleted.

You should only enable this if you don't like empty groups being left over in Gitlab after doing a purge in your directory.

**Only empty Gitlab groups will ever be deleted. If there are extra groups with members still in them they will not be deleted.**

Default: *false*

##### groupNamesOfAdministrators *(array|null)*

Specify a list of group names of which members should be granted administrator access.

This varies not only according to which directory software you're using, but also how your directory has been structured. Users that have directory administrator access should not necessarily have Gitlab administrator access too, so this one is up to you.

* For Microsoft Active Directory this is could be "Domain Admins" and "Enterprise Admins".
* OpenLDAP and 389-DS do not ship with such a group out of the box as they typically offer a "Directory Administrator" non-user object or similar for administrative purposes via bind DN.

This must be defined as an array even if you have only 1 group. Be sure to quote group names that have spaces. For example:

```
groupNamesOfAdministrators:
    - "Domain Admins"
    - "Enterprise Admins"
```

Default: *null*

##### groupNamesOfExternal *(array|null)*

Specify a list of group names of which members should be marked as external.

This varies not only according to which directory software you're using, but also how your directory has been structured.

* For Microsoft Active Directory this is could be "Domain Guests".
* OpenLDAP and 389-DS do not ship with such a group out of the box as they typically allow anonymous usage.

This must be defined as an array even if you have only 1 group. Be sure to quote group names that have spaces. For example:

```
groupNamesOfExternal:
    - "Domain Guests"
    - "Clients"
```

Default: *null*

#### instances *(array)*

Declare one or more Gitlab instances to sync with. Each array key represents the instance name, which can be used later on to only sync with a particular instance (out of multiple) when running this tool.

##### your-instance-name-here *(array)*

Make up an instance name. For example if you had multiple Gitlab installations on servers named "Athena" and "Demeter" it would be sensible to tag them as "athena" and "demeter" in your configuration. All sub-sections of this configuration will be repeated for each instance.

###### url *(string)*

Specify the full HTTP/HTTPS URL to this Gitlab instance, e.g. "https://athena.gitlab.example.com" or "https://demeter.gitlab.example.com". This is the same URL you use to really visit this Gitlab installation from your web browser.

###### token *(string)*

Specify an API token (usually a personal token or impersonation token) this tool can use to interface with the Gitlab instance's API. This token will need to have the "api" and "sudo" flags available.

###### ldapServerName *(string)*

Specify the LDAP server name used by this Gitlab instance. You can find this in the "ldap_servers" section of the "gitlab.rb" configuration file, which represents an array of data specifying how to interface with LDAP such as server host address, bind DN, encryption, base, etc.

## Running

Once you've configured this tool you can run it from a CLI using:

    `php bin/console ldap:sync -d`

Depending on your system's PHP installation you may need to use `php-cli` instead of `php`. (This typically only occurs on WHM/cPanel based servers configured to host PHP via the fast process manager, PHP-FPM.)

**The `-d` option is important for your first run.** This enables "dry run" mode, meaning no changes will be persisted to your Gitlab instances. After running this tool you should evaluate the changes that will be made based on the output, then run it again without the `-d` option to persist the changes.

If you'd like to see more verbose output you can add up to 3 `-v` switches, for example:

    `php bin/console ldap:sync -v`
    `php bin/console ldap:sync -vv`
    `php bin/console ldap:sync -vvv`

If you'd like to only sync with a single Gitlab instance you can specify the name of it as per your configuration as an argument, for example:

    `php bin/console ldap:sync athena`
    `php bin/console ldap:sync demeter`

## Built With

* [PHP](https://www.php.net): Entirely PHP.

## Contributing

Please read [CONTRIBUTING.md](https://gist.github.com/PurpleBooth/b24679402957c63ec426) for details on our code of conduct, and the process for submitting pull requests to us.

### Potential features

I don't have anything further planned as this fulfils my purpose.

## Versioning

We use [SemVer](http://semver.org/) for versioning. For the versions available, see the [tags on this repository](https://github.com/your/project/tags).

## Authors

* **Adam Reece** - *Initial work* - [Adambean](https://github.com/Adambean)

See also the list of [contributors](https://github.com/your/project/contributors) who participated in this project.

## License

Copyright 2018 Adam Reece

Licensed under the Apache License, Version 2.0 (the "License"); you may not use this file except in compliance with the License.

You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software distributed under the License is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the [License](LICENSE) for the specific language governing permissions and limitations under the License.
