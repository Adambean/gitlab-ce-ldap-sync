<?php

declare(strict_types=1);

namespace AdamReece\GitLabCeLdapSync;

use Cocur\Slugify\Slugify;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * @author  Adam "Adambean" Reece
 * @link    https://github.com/Adambean/gitlab-ce-ldap-sync
 * @license Apache License 2.0
 *
 * @phpstan-type ConfigGitLabArray array{
 *  url: non-empty-string,
 *  token: non-empty-string,
 *  ldapServerName: non-empty-string,
 * }
 *
 * @phpstan-type ConfigArray array{
 *  ldap: array{
 *      debug: bool,
 *      winCompatibilityMode: bool,
 *      server: array{
 *          host: non-empty-string,
 *          port: ?non-negative-int,
 *          version: positive-int,
 *          encryption: 'ssl'|'tls'|'none'|null,
 *          bindDn: ?string,
 *          bindPassword: ?string,
 *      },
 *      queries: array{
 *          baseDn: ?string,
 *          userDn: ?string,
 *          userFilter: ?string,
 *          userUniqueAttribute: ?string,
 *          userMatchAttribute: ?string,
 *          userNameAttribute: ?string,
 *          userEmailAttribute: ?string,
 *          groupDn: ?string,
 *          groupFilter: ?string,
 *          groupUniqueAttribute: ?string,
 *          groupMemberAttribute: ?string,
 *      },
 *  },
 *  gitlab: array{
 *      debug: bool,
 *      options: array{
 *          userNamesToIgnoreRegex: bool,
 *          userNamesToIgnore: non-empty-string[],
 *          groupNamesToIgnoreRegex: bool,
 *          groupNamesToIgnore: non-empty-string[],
 *          groupNamesPrefix: ?string,
 *          createEmptyGroups: bool,
 *          deleteExtraGroups: bool,
 *          newMemberAccessLevel: int,
 *          groupNamesOfAdministratorsRegex: bool,
 *          groupNamesOfAdministrators: non-empty-string[],
 *          groupNamesOfExternalRegex: bool,
 *          groupNamesOfExternal: non-empty-string[],
 *      },
 *      instances: array<non-empty-string, ConfigGitLabArray>,
 *  },
 * }
 *
 * @phpstan-type LdapUserArray array{
 *  dn: non-empty-string,
 *  username: non-empty-string,
 *  userMatchId: non-empty-string,
 *  fullName: non-empty-string,
 *  email: non-empty-string,
 *  isAdmin: bool,
 *  isExternal: bool,
 * }
 *
 * @phpstan-type GitLabUserArray array{
 *  id: int,
 *  username: string,
 *  email: string,
 *  name: string,
 *  state: string,
 *  locked: bool,
 *  avatar_url: ?string,
 *  web_url: ?string,
 *  created_at: string,
 *  is_admin: bool,
 *  bio: string,
 *  location: ?string,
 *  public_email: string,
 *  skype: string,
 *  linkedin: string,
 *  twitter: string,
 *  discord: string,
 *  website_url: string,
 *  organization: string,
 *  job_title: string,
 *  pronouns: string,
 *  work_information: ?string,
 *  followers: int,
 *  following: int,
 *  local_time: string,
 *  last_sign_in_at: string,
 *  confirmed_at: string,
 *  theme_id: int,
 *  last_activity_on: string,
 *  color_scheme_id: int,
 *  projects_limit: int,
 *  current_sign_in_at: string,
 *  note: string,
 *  identities: array{
 *      array{
 *          provider: string,
 *          extern_uid: string,
 *      }
 *  },
 *  can_create_group: bool,
 *  can_create_project: bool,
 *  two_factor_enabled: bool,
 *  external: bool,
 *  private_profile: bool,
 *  commit_email: string,
 *  current_sign_in_ip: string,
 *  last_sign_in_ip: string,
 *  plan: string,
 *  trial: bool,
 *  sign_in_count: int,
 *  namespace_id: int,
 *  created_by: ?string,
 * }
 *
 * @phpstan-type GitLabGroupArray array{
 *  id: int,
 *  name: string,
 *  path: string,
 *  description: string,
 *  visibility: string,
 *  share_with_group_lock: bool,
 *  require_two_factor_authentication: bool,
 *  two_factor_grace_period: int,
 *  project_creation_level: string,
 *  auto_devops_enabled: ?bool,
 *  subgroup_creation_level: string,
 *  emails_disabled: ?bool,
 *  emails_enabled: ?bool,
 *  mentions_disabled: ?bool,
 *  lfs_enabled: ?bool,
 *  default_branch_protection: int,
 *  default_branch_protection_defaults: array{
 *      allowed_to_push: array{
 *          access_level: int,
 *      },
 *      allow_force_push: bool,
 *      allowed_to_merge: array{
 *          access_level: int,
 *      },
 *  },
 *  avatar_url: ?string,
 *  web_url: ?string,
 *  request_access_enabled: bool,
 *  repository_storage: string,
 *  full_name: string,
 *  full_path: string,
 *  file_template_project_id: int,
 *  parent_id: ?int,
 *  created_at: string,
 *  ip_restriction_ranges: ?string,
 *  shared_runners_setting: string,
 *  ldap_cn: ?string,
 *  ldap_access: ?string,
 *  wiki_access_level: ?string,
 * }
 */
class LdapSyncCommand extends Command
{
    /*
     * -------------------------------------------------------------------------
     * Constants
     * -------------------------------------------------------------------------
     */

    /** @var string User's configuration file name. */
    public const CONFIG_FILE_NAME = "config.yml";

    /** @var string Distributed configuration file name. */
    public const CONFIG_FILE_DIST_NAME = "config.yml.dist";

    /** @var non-negative-int Wait this long (in microseconds) between GitLab API calls to avoid flooding. */
    public const API_COOL_DOWN_USECONDS = 100000;



    /*
     * -------------------------------------------------------------------------
     * Variables
     * -------------------------------------------------------------------------
     */

    /** @var OutputInterface|null Console output interface. */
    private ?OutputInterface $output = null;

    /** @var ConsoleLogger|null Console logger interface. */
    private ?ConsoleLogger $logger = null;

    /** @var bool Debug mode. */
    private bool $dryRun = false;

    /** @var bool Continue on failure: Do not abort on certain errors. */
    private bool $continueOnFail = false;

    /** @var string Application root directory. */
    private string $rootDir = "";

    /** @var string User's configuration file pathname. */
    private string $configFilePathname = "";

    /** @var string Distribution configuration file pathname. */
    private string $configFileDistPathname = "";



    /*
     * -------------------------------------------------------------------------
     * Command functions
     * -------------------------------------------------------------------------
     */

    /**
     * Configures the current command.
     */
    public function configure(): void
    {
        $this
            ->setName("ldap:sync")
            ->setDescription("Sync LDAP users and groups with a GitLab CE/EE self-hosted installation.")
            ->addOption("dryrun", "d", InputOption::VALUE_NONE, "Dry run: Do not persist any changes.")
            ->addOption("continueOnFail", null, InputOption::VALUE_NONE, "Do not abort on certain errors. (Continue running if possible.)")
            ->addArgument("instance", InputArgument::OPTIONAL, "Sync with a specific instance, or leave unspecified to work with all.")
        ;
    }

    /**
     * Executes the current command.
     *
     * @param InputInterface $input Input interface.
     * @param OutputInterface $output Output interface.
     *
     * @return int Error code, or zero for success.
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;
        $this->logger = new ConsoleLogger($output);
        $output->writeln("LDAP users and groups sync script for GitLab-CE\n");

        // Prepare
        if ($this->dryRun = boolval($input->getOption("dryrun"))) {
            $this->logger->warning("Dry run enabled: No changes will be persisted.");
        }

        if ($this->continueOnFail = boolval($input->getOption("continueOnFail"))) {
            $this->logger->warning("Continue on failure enabled: Certain errors will be ignored if possible.");
        }

        $this->rootDir                  = sprintf("%s/../", __DIR__);
        $this->configFilePathname       = sprintf("%s/%s", $this->rootDir, self::CONFIG_FILE_NAME);
        $this->configFileDistPathname   = sprintf("%s/%s", $this->rootDir, self::CONFIG_FILE_DIST_NAME);

        foreach ([
            "ldap_connect",
            "ldap_bind",
            "ldap_set_option",
            "ldap_errno",
            "ldap_error",
            "ldap_search",
            "ldap_get_entries",
        ] as $ldapFunction) {
            // @phpstan-ignore function.alreadyNarrowedType
            if (!function_exists($ldapFunction)) {
                $this->logger->critical(sprintf("PHP-LDAP function \"%s\" does not exist.", $ldapFunction));
                return Command::FAILURE;
            }
        }



        // Load configuration
        $this->logger->notice("Loading configuration.", ["file" => $this->configFilePathname]);

        if (null === ($config = $this->loadConfig($this->configFilePathname))) {
            $this->logger->debug("Checking if default configuration exists but user configuration does not.", [
                "file" => $this->configFileDistPathname
            ]);
            if (file_exists($this->configFileDistPathname) && !file_exists($this->configFilePathname)) {
                $this->logger->warning("Dist config found but user config not.");
                $output->writeln(sprintf(
                    "It appears that you have not created a configuration yet.\nPlease duplicate \"%s\" as \"%s\", then modify it for your\nenvironment.",
                    self::CONFIG_FILE_DIST_NAME,
                    self::CONFIG_FILE_NAME
                ));
            }

            return Command::FAILURE;
        }

        $this->logger->notice("Loaded configuration.", ["file" => $this->configFilePathname, "config" => $config]);



        // Validate configuration
        $this->logger->notice("Validating configuration.");

        $configProblems = [];
        if (!$this->validateConfig($config, $configProblems)) {
            $this->logger->error(sprintf(
                "%d configuration problem(s) need to be resolved.",
                count($configProblems["error"])
            ));
            return Command::INVALID;
        }

        $this->logger->notice("Validated configuration.");



        // Retrieve groups from LDAP
        $this->logger->notice("Retrieving directory users and groups.");

        $ldapUsers      = [];
        $ldapUsersNum   = 0;
        $ldapGroups     = [];
        $ldapGroupsNum  = 0;

        try {
            $this->getLdapUsersAndGroups($config, $ldapUsers, $ldapUsersNum, $ldapGroups, $ldapGroupsNum);
        } catch (\Exception $e) {
            $this->logger->error(sprintf("LDAP failure: %s", $e->getMessage()), ["error" => $e]);
            return Command::FAILURE;
        }

        $this->logger?->notice("Retrieved directory users and groups.");



        // Check if we have anything to do...
        /* Actually, we might still want to sync users and groups aren't any of one set yet.
        if (!is_array($ldapUsers) || $ldapUsersNum < 1) {
            $this->logger->error("Nothing to do: No users found in the directory.");
            return Command::INVALID;
        }

        if (!is_array($ldapGroups) || $ldapGroupsNum < 1) {
            $this->logger->error("Nothing to do: No groups found in the directory.");
            return Command::INVALID;
        }
         */



        // Deploy to GitLab instances
        $this->logger?->notice("Deploying users and groups to GitLab instances.");

        $gitLabInstanceOnly = is_string($gitLabInstanceOnly = $input->getArgument("instance"))
            ? trim($gitLabInstanceOnly)
            : null
        ;
        foreach ($config["gitlab"]["instances"] as $gitLabInstance => $gitLabConfig) {
            if (is_string($gitLabInstanceOnly) && $gitLabInstance !== $gitLabInstanceOnly) {
                    $this->logger?->debug(sprintf(
                        "Skipping instance \"%s\", doesn't match the argument specified.",
                        $gitLabInstance
                    ));
                continue;
            }

            try {
                $this->deployGitLabUsersAndGroups(
                    $config,
                    $gitLabInstance,
                    $gitLabConfig,
                    $ldapUsers,
                    $ldapUsersNum,
                    $ldapGroups,
                    $ldapGroupsNum
                );
            } catch (\Exception $e) {
                $this->logger?->error(sprintf("GitLab failure: %s", $e->getMessage()), ["error" => $e]);
                return Command::FAILURE;
            }
        }

        $this->logger?->notice("Deployed users and groups to GitLab instances.");



        // Finished
        $output->writeln("Finished.");
        return Command::SUCCESS;
    }



    /*
     * -------------------------------------------------------------------------
     * Helper functions
     * -------------------------------------------------------------------------
     */

    /**
     * Load configuration.
     *
     * @param string $file File pathname
     *
     * @return ConfigArray|null Configuration, or null if failed
     */
    private function loadConfig(string $file): ?array
    {
        if ("" === ($file = trim($file))) {
            $this->logger?->critical("Configuration file not specified.");
            return null;
        }

        if (!file_exists($file)) {
            $this->logger?->critical("Configuration file not found.");
            return null;
        }

        if (!is_file($file)) {
            $this->logger?->critical("Configuration file not a file.");
            return null;
        }

        if (!is_readable($file)) {
            $this->logger?->critical("Configuration file not readable.");
            return null;
        }

        $yaml = null;

        try {
            $yaml = Yaml::parseFile($file);
        } catch (ParseException $e) {
            $this->logger?->critical(sprintf("Configuration file could not be parsed: %s", $e->getMessage()));
            return null;
        }

        if (!is_array($yaml)) {
            $this->logger?->critical("Configuration format invalid.");
            return null;
        }

        if ([] === $yaml) {
            $this->logger?->critical("Configuration empty.");
            return null;
        }

        /** @var ConfigArray $yaml */
        return $yaml;
    }

    /**
     * Validate configuration.
     *
     * @param ConfigArray $config Configuration.
     * @param-out ConfigArray $config Configuration, modified for type strictness and trimming.
     * @param array<non-empty-string, non-empty-string[]> $problems Output of problems, holder reference.
     * @param-out array<non-empty-string, non-empty-string[]> $problems Output of problems indexed by type.
     *
     * @return bool True if valid, false if invalid
     */
    private function validateConfig(array &$config, array &$problems): bool
    {
        $problems = [
            "warning"   => [],
            "error"     => [],
        ];

        /**
         * @var \Closure(string $type, string $message):void $addProblem Add a problem.
         *
         * @param 'error'|'warning' $type Problem type.
         * @param string $message Problem description.
         *
         * @uses array<'error'|'warning', string[]> $problems Output of problems indexed by type.
         *
         * @return void
         */
        $addProblem = function (string $type, string $message) use (&$problems): void {

            if ("" === ($type = trim($type))) {
                return;
            }

            if (!isset($problems[$type]) || !is_array($problems[$type])) {
                throw new \UnexpectedValueException("Type invalid.");
            }

            if ("" === ($message = trim($message))) {
                return;
            }

            if (null === $this->logger || !method_exists($this->logger, $type)) {
                return;
            }

            /** @phpstan-ignore method.dynamicName */
            $this->logger->$type(sprintf("Configuration: %s", $message));
            $problems[$type][] = $message;

        };

        // << LDAP
        if (!isset($config["ldap"]) || !is_array($config["ldap"])) {
            $addProblem("error", "ldap missing.");
        } else {
            if (!isset($config["ldap"]["debug"])) {
                $addProblem("warning", "ldap->debug missing. (Assuming false.)");
                $config["ldap"]["debug"] = false;
            } elseif ("" === $config["ldap"]["debug"]) {
                $addProblem("warning", "ldap->debug not specified. (Assuming false.)");
                $config["ldap"]["debug"] = false;
            } elseif (!is_bool($config["ldap"]["debug"])) {
                $addProblem("error", "ldap->debug is not a boolean.");
            }

            // << LDAP server
            if (!isset($config["ldap"]["server"]) || !is_array($config["ldap"]["server"])) {
                $addProblem("error", "ldap->server missing.");
            } else {
                if (!isset($config["ldap"]["server"]["host"])) {
                    $addProblem("error", "ldap->server->host missing.");
                } elseif ("" === ($config["ldap"]["server"]["host"] = trim($config["ldap"]["server"]["host"]))) {
                    $addProblem("error", "ldap->server->host not specified.");
                }

                if (!isset($config["ldap"]["server"]["port"])) {
                    $addProblem("warning", "ldap->server->port missing. (It will be determined by the encryption setting.)");
                    $config["ldap"]["server"]["port"] = null;
                } elseif (0 === ($config["ldap"]["server"]["port"] = intval($config["ldap"]["server"]["port"]))) {
                    $addProblem("warning", "ldap->server->port not specified. (It will be determined by the encryption setting.)");
                    $config["ldap"]["server"]["port"] = null;
                } elseif ($config["ldap"]["server"]["port"] < 1 || $config["ldap"]["server"]["port"] > 65535) {
                    $addProblem("error", "ldap->server->port out of range. (Must be 1-65535.)");
                }

                if (!isset($config["ldap"]["server"]["version"])) {
                    $addProblem("warning", "ldap->server->version missing. (Assuming 3.)");
                    $config["ldap"]["server"]["version"] = 3;
                } elseif (0 === ($config["ldap"]["server"]["version"] = intval($config["ldap"]["server"]["version"]))) {
                    $addProblem("warning", "ldap->server->version not specified. (Assuming 3.)");
                    $config["ldap"]["server"]["version"] = 3;
                } elseif ($config["ldap"]["server"]["version"] < 1 || $config["ldap"]["server"]["version"] > 3) {
                    $addProblem("error", "ldap->server->version out of range. (Must be 1-3.)");
                }

                if (!isset($config["ldap"]["server"]["encryption"])) {
                    $addProblem("warning", "ldap->server->encryption missing. (Assuming none.)");
                    $config["ldap"]["server"]["encryption"] = "none";
                } elseif ("" === ($config["ldap"]["server"]["encryption"] = trim($config["ldap"]["server"]["encryption"]))) {
                    $addProblem("warning", "ldap->server->encryption not specified. (Assuming none.)");
                    $config["ldap"]["server"]["encryption"] = "none";
                } else {
                    switch ($config["ldap"]["server"]["encryption"]) {
                        case "none":
                        case "tls":
                            if (!is_int($config["ldap"]["server"]["port"]) || 0 === $config["ldap"]["server"]["port"]) {
                                $config["ldap"]["server"]["port"] = 389;
                            }
                            break;

                        case "ssl":
                            if (!is_int($config["ldap"]["server"]["port"]) || 0 === $config["ldap"]["server"]["port"]) {
                                $config["ldap"]["server"]["port"] = 636;
                            }
                            break;

                        default:
                            $addProblem("error", "ldap->server->encryption invalid. (Must be \"none\", \"ssl\", or \"tls\".)");
                    }
                }

                if (!isset($config["ldap"]["server"]["bindDn"])) {
                    $addProblem("warning", "ldap->server->bindDn missing. (Assuming anonymous access.)");
                    $config["ldap"]["server"]["bindDn"] = null;
                } elseif ("" === ($config["ldap"]["server"]["bindDn"] = trim($config["ldap"]["server"]["bindDn"]))) {
                    $addProblem("warning", "ldap->server->bindDn not specified. (Assuming anonymous access.)");
                    $config["ldap"]["server"]["bindDn"] = null;
                } else {
                    if (!isset($config["ldap"]["server"]["bindPassword"])) {
                        $addProblem("warning", "ldap->server->bindPassword missing. (Must be specified for non-anonymous access.)");
                    } elseif ("" === $config["ldap"]["server"]["bindPassword"]) {
                        $addProblem("warning", "ldap->server->bindPassword not specified. (Must be specified for non-anonymous access.)");
                    }
                }
            }
            // >> LDAP server

            // << LDAP queries
            if (!isset($config["ldap"]["queries"])) {
                $addProblem("error", "ldap->queries missing.");
            } else {
                if (!isset($config["ldap"]["queries"]["baseDn"])) {
                    $addProblem("error", "ldap->queries->baseDn missing.");
                } elseif ("" === ($config["ldap"]["queries"]["baseDn"] = trim($config["ldap"]["queries"]["baseDn"]))) {
                    $addProblem("error", "ldap->queries->baseDn not specified.");
                }

                if (!isset($config["ldap"]["queries"]["userDn"])) {
                    $addProblem("error", "ldap->queries->userDn missing.");
                } elseif ("" === ($config["ldap"]["queries"]["userDn"] = trim($config["ldap"]["queries"]["userDn"]))) {
                    // $addProblem("warning", "ldap->queries->userDn not specified.");
                    // This is OK: Users will be looked for from the directory root.
                }

                if (
                    is_string($config["ldap"]["queries"]["baseDn"]) &&
                    "" !== $config["ldap"]["queries"]["baseDn"] &&
                    is_string($config["ldap"]["queries"]["userDn"]) &&
                    "" !== $config["ldap"]["queries"]["userDn"] &&
                    strripos($config["ldap"]["queries"]["userDn"], $config["ldap"]["queries"]["baseDn"]) === (strlen($config["ldap"]["queries"]["userDn"]) - strlen($config["ldap"]["queries"]["baseDn"]))
                ) {
                    $addProblem("warning", "ldap->queries->userDn wrongly ends with ldap->queries->baseDn, this could cause user objects to not be found.");
                }

                if (!isset($config["ldap"]["queries"]["userFilter"])) {
                    $addProblem("error", "ldap->queries->userFilter missing.");
                } elseif ("" === ($config["ldap"]["queries"]["userFilter"] = trim($config["ldap"]["queries"]["userFilter"]))) {
                    $addProblem("error", "ldap->queries->userFilter not specified.");
                }

                if (!isset($config["ldap"]["queries"]["userUniqueAttribute"])) {
                    $addProblem("error", "ldap->queries->userUniqueAttribute missing.");
                } elseif ("" === ($config["ldap"]["queries"]["userUniqueAttribute"] = trim($config["ldap"]["queries"]["userUniqueAttribute"]))) {
                    $addProblem("error", "ldap->queries->userUniqueAttribute not specified.");
                }

                if (!isset($config["ldap"]["queries"]["userMatchAttribute"])) {
                    $addProblem("warning", "ldap->queries->userMatchAttribute missing. (Assuming == userUniqueAttribute.)");
                    $config["ldap"]["queries"]["userMatchAttribute"] = $config["ldap"]["queries"]["userUniqueAttribute"];
                } elseif ("" === ($config["ldap"]["queries"]["userMatchAttribute"] = trim($config["ldap"]["queries"]["userMatchAttribute"]))) {
                    $addProblem("warning", "ldap->queries->userMatchAttribute not specified. (Assuming == userUniqueAttribute.)");
                    $config["ldap"]["queries"]["userMatchAttribute"] = $config["ldap"]["queries"]["userUniqueAttribute"];
                }

                if (!isset($config["ldap"]["queries"]["userNameAttribute"])) {
                    $addProblem("error", "ldap->queries->userNameAttribute missing.");
                } elseif ("" === ($config["ldap"]["queries"]["userNameAttribute"] = trim($config["ldap"]["queries"]["userNameAttribute"]))) {
                    $addProblem("error", "ldap->queries->userNameAttribute not specified.");
                }

                if (!isset($config["ldap"]["queries"]["userEmailAttribute"])) {
                    $addProblem("error", "ldap->queries->userEmailAttribute missing.");
                } elseif ("" === ($config["ldap"]["queries"]["userEmailAttribute"] = trim($config["ldap"]["queries"]["userEmailAttribute"]))) {
                    $addProblem("error", "ldap->queries->userEmailAttribute not specified.");
                }

                if (!isset($config["ldap"]["queries"]["groupDn"])) {
                    $addProblem("error", "ldap->queries->groupDn missing.");
                } elseif ("" === ($config["ldap"]["queries"]["groupDn"] = trim($config["ldap"]["queries"]["groupDn"]))) {
                    // $addProblem("error", "ldap->queries->groupDn not specified.");
                    // This is OK: Groups will be looked for from the directory root.
                }

                if (
                    is_string($config["ldap"]["queries"]["baseDn"]) &&
                    "" !== $config["ldap"]["queries"]["baseDn"] &&
                    is_string($config["ldap"]["queries"]["groupDn"]) &&
                    "" !== $config["ldap"]["queries"]["groupDn"] &&
                    strripos($config["ldap"]["queries"]["groupDn"], $config["ldap"]["queries"]["baseDn"]) === (strlen($config["ldap"]["queries"]["groupDn"]) - strlen($config["ldap"]["queries"]["baseDn"]))
                ) {
                    $addProblem("warning", "ldap->queries->groupDn wrongly ends with ldap->queries->baseDn, this could cause user objects to not be found.");
                }

                if (!isset($config["ldap"]["queries"]["groupFilter"])) {
                    $addProblem("error", "ldap->queries->groupFilter missing.");
                } elseif ("" === ($config["ldap"]["queries"]["groupFilter"] = trim($config["ldap"]["queries"]["groupFilter"]))) {
                    $addProblem("error", "ldap->queries->groupFilter not specified.");
                }

                if (!isset($config["ldap"]["queries"]["groupUniqueAttribute"])) {
                    $addProblem("error", "ldap->queries->groupUniqueAttribute missing.");
                } elseif ("" === ($config["ldap"]["queries"]["groupUniqueAttribute"] = trim($config["ldap"]["queries"]["groupUniqueAttribute"]))) {
                    $addProblem("error", "ldap->queries->groupUniqueAttribute not specified.");
                }

                if (!isset($config["ldap"]["queries"]["groupMemberAttribute"])) {
                    $addProblem("error", "ldap->queries->groupMemberAttribute missing.");
                } elseif ("" === ($config["ldap"]["queries"]["groupMemberAttribute"] = trim($config["ldap"]["queries"]["groupMemberAttribute"]))) {
                    $addProblem("error", "ldap->queries->groupMemberAttribute not specified.");
                }
            }
            // >> LDAP queries
        }
        // >> LDAP

        // << GitLab
        if (!isset($config["gitlab"]) || !is_array($config["gitlab"])) {
            $addProblem("error", "gitlab missing.");
        } else {
            if (!isset($config["gitlab"]["debug"])) {
                $addProblem("warning", "gitlab->debug missing. (Assuming false.)");
                $config["gitlab"]["debug"] = false;
            } elseif ("" === $config["gitlab"]["debug"]) {
                $addProblem("warning", "gitlab->debug not specified. (Assuming false.)");
                $config["gitlab"]["debug"] = false;
            } elseif (!is_bool($config["gitlab"]["debug"])) {
                $addProblem("error", "gitlab->debug is not a boolean.");
            }

            // << GitLab options
            if (!isset($config["gitlab"]["options"]) || !is_array($config["gitlab"]["options"])) {
                $addProblem("error", "gitlab->options missing.");
            } else {
                if (!isset($config["gitlab"]["options"]["userNamesToIgnore"])) {
                    $addProblem("warning", "gitlab->options->userNamesToIgnore missing. (Assuming none.)");
                    $config["gitlab"]["options"]["userNamesToIgnore"] = [];
                } elseif ("" === $config["gitlab"]["options"]["userNamesToIgnore"]) {
                    // $addProblem("warning", "gitlab->options->userNamesToIgnore not specified. (Assuming none.)");
                    $config["gitlab"]["options"]["userNamesToIgnore"] = [];
                } elseif (!is_array($config["gitlab"]["options"]["userNamesToIgnore"])) {
                    $addProblem("error", "gitlab->options->userNamesToIgnore is not an array.");
                } elseif ([] !== $config["gitlab"]["options"]["userNamesToIgnore"]) {
                    foreach ($config["gitlab"]["options"]["userNamesToIgnore"] as $i => $userName) {
                        if (!is_string($userName)) {
                            $addProblem("error", sprintf("gitlab->options->userNamesToIgnore[%d] is not a string.", $i));
                            continue;
                        }

                        if ("" === ($config["gitlab"]["options"]["userNamesToIgnore"][$i] = trim($userName))) {
                            $addProblem("error", sprintf("gitlab->options->userNamesToIgnore[%d] not specified.", $i));
                            continue;
                        }

                        if($config["gitlab"]["options"]["userNamesToIgnoreRegex"] && @preg_match($userName, "") === false) {
                            $addProblem("error", sprintf("gitlab->options->userNamesToIgnore[%d] is invalid regex.", $i));
                            continue;
                        }

                    }
                }

                if (!isset($config["gitlab"]["options"]["groupNamesToIgnore"])) {
                    $addProblem("warning", "gitlab->options->groupNamesToIgnore missing. (Assuming none.)");
                    $config["gitlab"]["options"]["groupNamesToIgnore"] = [];
                } elseif ("" === $config["gitlab"]["options"]["groupNamesToIgnore"]) {
                    // $addProblem("warning", "gitlab->options->groupNamesToIgnore not specified. (Assuming none.)");
                    $config["gitlab"]["options"]["groupNamesToIgnore"] = [];
                } elseif (!is_array($config["gitlab"]["options"]["groupNamesToIgnore"])) {
                    $addProblem("error", "gitlab->options->groupNamesToIgnore is not an array.");
                } elseif ([] !== $config["gitlab"]["options"]["groupNamesToIgnore"]) {
                    foreach ($config["gitlab"]["options"]["groupNamesToIgnore"] as $i => $groupName) {
                        if (!is_string($groupName)) {
                            $addProblem("error", sprintf("gitlab->options->groupNamesToIgnore[%d] is not a string.", $i));
                            continue;
                        }

                        if ("" === ($config["gitlab"]["options"]["groupNamesToIgnore"][$i] = trim($groupName))) {
                            $addProblem("error", sprintf("gitlab->options->groupNamesToIgnore[%d] not specified.", $i));
                            continue;
                        }

                        if($config["gitlab"]["options"]["groupNamesToIgnoreRegex"] && @preg_match($groupName, "") === false) {
                            $addProblem("error", sprintf("gitlab->options->groupNamesToIgnore[%d] is invalid regex.", $i));
                            continue;
                        }
                    }
                }

                if (!isset($config["gitlab"]["options"]["groupNamesPrefix"])) {
                    $config["gitlab"]["options"]["groupNamesPrefix"] = null;
                } elseif ("" === ($config["gitlab"]["options"]["groupNamesPrefix"] = trim((string) $config["gitlab"]["options"]["groupNamesPrefix"]))) {
                    $config["gitlab"]["options"]["groupNamesPrefix"] = null;
                }

                if (!isset($config["gitlab"]["options"]["createEmptyGroups"])) {
                    $addProblem("warning", "gitlab->options->createEmptyGroups missing. (Assuming false.)");
                    $config["gitlab"]["options"]["createEmptyGroups"] = false;
                } elseif ("" === $config["gitlab"]["options"]["createEmptyGroups"]) {
                    $addProblem("warning", "gitlab->options->createEmptyGroups not specified. (Assuming false.)");
                    $config["gitlab"]["options"]["createEmptyGroups"] = false;
                } elseif (!is_bool($config["gitlab"]["options"]["createEmptyGroups"])) {
                    $addProblem("error", "gitlab->options->createEmptyGroups is not a boolean.");
                }

                if (!isset($config["gitlab"]["options"]["deleteExtraGroups"])) {
                    $addProblem("warning", "gitlab->options->deleteExtraGroups missing. (Assuming false.)");
                    $config["gitlab"]["options"]["deleteExtraGroups"] = false;
                } elseif ("" === $config["gitlab"]["options"]["deleteExtraGroups"]) {
                    $addProblem("warning", "gitlab->options->deleteExtraGroups not specified. (Assuming false.)");
                    $config["gitlab"]["options"]["deleteExtraGroups"] = false;
                } elseif (!is_bool($config["gitlab"]["options"]["deleteExtraGroups"])) {
                    $addProblem("error", "gitlab->options->deleteExtraGroups is not a boolean.");
                }

                if (!isset($config["gitlab"]["options"]["newMemberAccessLevel"])) {
                    $addProblem("warning", "gitlab->options->newMemberAccessLevel missing. (Assuming 30.)");
                    $config["gitlab"]["options"]["newMemberAccessLevel"] = 30;
                } elseif ("" === $config["gitlab"]["options"]["newMemberAccessLevel"]) {
                    $addProblem("warning", "gitlab->options->newMemberAccessLevel not specified. (Assuming 30.)");
                    $config["gitlab"]["options"]["newMemberAccessLevel"] = 30;
                } elseif (!is_int($config["gitlab"]["options"]["newMemberAccessLevel"])) {
                    $addProblem("error", "gitlab->options->newMemberAccessLevel is not an integer.");
                }

                if (!isset($config["gitlab"]["options"]["groupNamesOfAdministrators"])) {
                    // $addProblem("warning", "gitlab->options->groupNamesOfAdministrators missing. (Assuming none.)");
                    $config["gitlab"]["options"]["groupNamesOfAdministrators"] = [];
                } elseif ("" === $config["gitlab"]["options"]["groupNamesOfAdministrators"]) {
                    $addProblem("warning", "gitlab->options->groupNamesOfAdministrators not specified. (Assuming none.)");
                    $config["gitlab"]["options"]["groupNamesOfAdministrators"] = [];
                } elseif (!is_array($config["gitlab"]["options"]["groupNamesOfAdministrators"])) {
                    $addProblem("error", "gitlab->options->groupNamesOfAdministrators is not an array.");
                } elseif ([] !== $config["gitlab"]["options"]["groupNamesOfAdministrators"]) {
                    foreach ($config["gitlab"]["options"]["groupNamesOfAdministrators"] as $i => $groupName) {
                        if (!is_string($groupName)) {
                            $addProblem("error", sprintf("gitlab->options->groupNamesOfAdministrators[%d] is not a string.", $i));
                            continue;
                        }

                        if ("" === ($config["gitlab"]["options"]["groupNamesOfAdministrators"][$i] = trim($groupName))) {
                            $addProblem("error", sprintf("gitlab->options->groupNamesOfAdministrators[%d] not specified.", $i));
                            continue;
                        }

                        if($config["gitlab"]["options"]["groupNamesOfAdministratorsRegex"] && @preg_match($groupName, "") === false) {
                            $addProblem("error", sprintf("gitlab->options->groupNamesOfAdministrators[%d] is invalid regex.", $i));
                            continue;
                        }
                    }
                }

                if (!isset($config["gitlab"]["options"]["groupNamesOfExternal"])) {
                    $addProblem("warning", "gitlab->options->groupNamesOfExternal missing. (Assuming none.)");
                    $config["gitlab"]["options"]["groupNamesOfExternal"] = [];
                } elseif ("" === $config["gitlab"]["options"]["groupNamesOfExternal"]) {
                    // $addProblem("warning", "gitlab->options->groupNamesOfExternal not specified. (Assuming none.)");
                    $config["gitlab"]["options"]["groupNamesOfExternal"] = [];
                } elseif (!is_array($config["gitlab"]["options"]["groupNamesOfExternal"])) {
                    $addProblem("error", "gitlab->options->groupNamesOfExternal is not an array.");
                } elseif ([] !== $config["gitlab"]["options"]["groupNamesOfExternal"]) {
                    foreach ($config["gitlab"]["options"]["groupNamesOfExternal"] as $i => $groupName) {
                        if (!is_string($groupName)) {
                            $addProblem("error", sprintf("gitlab->options->groupNamesOfExternal[%d] is not a string.", $i));
                            continue;
                        }

                        if ("" === ($config["gitlab"]["options"]["groupNamesOfExternal"][$i] = trim($groupName))) {
                            $addProblem("error", sprintf("gitlab->options->groupNamesOfExternal[%d] not specified.", $i));
                            continue;
                        }

                        if($config["gitlab"]["options"]["groupNamesOfExternalRegex"] && @preg_match($groupName, "") === false) {
                            $addProblem("error", sprintf("gitlab->options->groupNamesOfExternal[%d] is invalid regex.", $i));
                            continue;
                        }
                    }
                }
            }
            // >> GitLab options

            // << GitLab instances
            if (!isset($config["gitlab"]["instances"]) || !is_array($config["gitlab"]["instances"])) {
                $addProblem("error", "gitlab->instances missing.");
            } else {
                foreach (array_keys($config["gitlab"]["instances"]) as $instance) {
                    if (!is_string($instance)) {
                        $instance = strval($instance);
                    }

                    if (!isset($config["gitlab"]["instances"][$instance]["url"])) {
                        $addProblem("error", sprintf("gitlab->instances->%s->url missing.", $instance));
                    } elseif ("" === ($config["gitlab"]["instances"][$instance]["url"] = trim($config["gitlab"]["instances"][$instance]["url"]))) {
                        $addProblem("error", sprintf("gitlab->instances->%s->url not specified.", $instance));
                    }

                    if (!isset($config["gitlab"]["instances"][$instance]["token"])) {
                        $addProblem("error", sprintf("gitlab->instances->%s->token missing.", $instance));
                    } elseif ("" === ($config["gitlab"]["instances"][$instance]["token"] = trim($config["gitlab"]["instances"][$instance]["token"]))) {
                        $addProblem("error", sprintf("gitlab->instances->%s->token not specified.", $instance));
                    }
                }
            }
            // >> GitLab instances
        }
        // >> GitLab

        return ([] === $problems["error"]);
    }

    /**
     * Get users and groups from LDAP.
     *
     * @param ConfigArray $config Validated configuration.
     * @param array<non-empty-string, LdapUserArray> $users Users holder.
     * @param-out array<non-empty-string, LdapUserArray> $users Users output.
     * @param non-negative-int $usersNum Users count holder.
     * @param-out non-negative-int $usersNum Users count output.
     * @param array<non-empty-string, non-empty-string[]> $groups Groups holder.
     * @param-out array<non-empty-string, non-empty-string[]> $groups Groups output.
     * @param non-negative-int $groupsNum Groups count holder.
     * @param-out non-negative-int $groupsNum Groups count output.
     */
    private function getLdapUsersAndGroups(
        array $config,
        array &$users,
        int &$usersNum,
        array &$groups,
        int &$groupsNum,
    ): void {
        $this->output?->writeln("Getting users and groups from LDAP...");

        $slugifyLdapUsername = new Slugify([
            "regexp"        => "/([^A-Za-z0-9-_\.])+/",
            "separator"     => ",",
            "lowercase"     => false,
            "trim"          => true,
        ]);

        // Connect
        $this->logger?->notice("Establishing LDAP connection.", [
            "host"          => $config["ldap"]["server"]["host"],
            "port"          => $config["ldap"]["server"]["port"],
            "version"       => $config["ldap"]["server"]["version"],
            "encryption"    => $config["ldap"]["server"]["encryption"],
            "bindDn"        => $config["ldap"]["server"]["bindDn"],
        ]);

        $ldap       = null;
        $ldapUri    = sprintf(
            "ldap%s://%s:%d/",
            "ssl" === $config["ldap"]["server"]["encryption"] ? "s" : "",
            $config["ldap"]["server"]["host"],
            $config["ldap"]["server"]["port"]
        );

        if ($config["ldap"]["debug"]) {
            $this->logger?->debug("LDAP: Enabling debug mode");
            @ldap_set_option(null, LDAP_OPT_DEBUG_LEVEL, 6);
        }

        // Solves: ldap_search(): Search: Operations error.
        // Occurs when no "user_dn" has been specified.
        // https://stackoverflow.com/questions/17742751/ldap-operations-error
        if ($config["ldap"]["winCompatibilityMode"]) {
            $this->logger?->debug("LDAP: Enabling compatibility mode");
            @ldap_set_option(null, LDAP_OPT_REFERRALS, 0);
        }

        $this->logger?->debug("LDAP: Connecting", ["uri" => $ldapUri]);
        if (false === ($ldap = @ldap_connect($ldapUri))) {
            throw new \RuntimeException(sprintf(
                "LDAP connection will not be possible. Check that your server address and port \"%s\" are plausible.",
                $ldapUri
            ));
        }

        $this->logger?->debug("LDAP: Setting options");
        if (false === @ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, $config["ldap"]["server"]["version"])) {
            throw new \RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
        }

        if ("tls" === $config["ldap"]["server"]["encryption"]) {
            $this->logger?->debug("LDAP: STARTTLS");
            if (false === @ldap_start_tls($ldap)) {
                throw new \RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
            }
        }

        $this->logger?->debug("LDAP: Binding", ["dn" => $config["ldap"]["server"]["bindDn"]]);
        if (false === @ldap_bind(
            $ldap,
            $config["ldap"]["server"]["bindDn"],
            $config["ldap"]["server"]["bindPassword"]
        )) {
            throw new \RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
        }

        $this->logger?->notice("LDAP connection established.");

        // << Retrieve users
        $ldapUsersQueryBase = sprintf(
            "%s%s%s",
            $config["ldap"]["queries"]["userDn"],
            null !== $config["ldap"]["queries"]["userDn"] && "" !== $config["ldap"]["queries"]["userDn"] ? "," : "",
            $config["ldap"]["queries"]["baseDn"]
        );

        $ldapUsersQueryAttributes = [
            $config["ldap"]["queries"]["userUniqueAttribute"],
            $config["ldap"]["queries"]["userMatchAttribute"],
            $config["ldap"]["queries"]["userNameAttribute"],
            $config["ldap"]["queries"]["userEmailAttribute"],
        ];

        $this->logger?->debug("Retrieving users.", [
            "base"          => $ldapUsersQueryBase,
            "filter"        => $config["ldap"]["queries"]["userFilter"],
            "attributes"    => $ldapUsersQueryAttributes,
        ]);

        $ldapUsersQuery = @ldap_search(
            $ldap,
            $ldapUsersQueryBase,
            strval($config["ldap"]["queries"]["userFilter"]),
            $ldapUsersQueryAttributes
        );
        if (false === $ldapUsersQuery || !($ldapUsersQuery instanceof \LDAP\Result)) {
            throw new \RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
        }

        $ldapUserAttribute      = is_string($config["ldap"]["queries"]["userUniqueAttribute"])
            ? strtolower($config["ldap"]["queries"]["userUniqueAttribute"])
            : "uid"
        ;
        $ldapUserMatchAttribute = is_string($config["ldap"]["queries"]["userMatchAttribute"])
            ? strtolower($config["ldap"]["queries"]["userMatchAttribute"])
            : "uid"
        ;
        $ldapNameAttribute      = is_string($config["ldap"]["queries"]["userNameAttribute"])
            ? strtolower($config["ldap"]["queries"]["userNameAttribute"])
            : "cn"
        ;
        $ldapEmailAttribute     = is_string($config["ldap"]["queries"]["userEmailAttribute"])
            ? strtolower($config["ldap"]["queries"]["userEmailAttribute"])
            : "mail"
        ;

        $ldapUsers = @ldap_get_entries($ldap, $ldapUsersQuery);
        if (is_array($ldapUsers)) {
            if (($ldapUsersNum = count($ldapUsers)) >= 1) {
                $this->logger?->notice(sprintf("%d directory user(s) found.", $ldapUsersNum));

                foreach ($ldapUsers as $i => $ldapUser) {
                    if (!is_int($i)) {
                        continue;
                    }
                    $n = $i + 1;

                    if (!is_array($ldapUser)) {
                        $this->logger?->error(sprintf("User #%d: Not an array.", $n));
                        continue;
                    }

                    if (!isset($ldapUser["dn"]) || !is_string($ldapUser["dn"])) {
                        $this->logger?->error(sprintf("User #%d: Missing distinguished name.", $n));
                        continue;
                    }

                    if ("" === ($ldapUserDn = trim($ldapUser["dn"]))) {
                        $this->logger?->error(sprintf("User #%d: Empty distinguished name.", $n));
                        continue;
                    }

                    if (!isset($ldapUser[$ldapUserAttribute])) {
                        $this->logger?->error(sprintf(
                            "User #%d [%s]: Missing attribute \"%s\".",
                            $n,
                            $ldapUserDn,
                            $ldapUserAttribute
                        ));
                        continue;
                    }

                    if (!is_array(
                        $ldapUser[$ldapUserAttribute])
                        || !isset($ldapUser[$ldapUserAttribute][0])
                        || !is_string($ldapUser[$ldapUserAttribute][0])
                    ) {
                        $this->logger?->error(sprintf(
                            "User #%d [%s]: Invalid attribute \"%s\".",
                            $n,
                            $ldapUserDn,
                            $ldapUserAttribute
                        ));
                        continue;
                    }

                    if ("" === ($ldapUserName = trim($ldapUser[$ldapUserAttribute][0]))) {
                        $this->logger?->error(sprintf(
                            "User #%d [%s]: Empty attribute \"%s\".",
                            $n,
                            $ldapUserDn,
                            $ldapUserAttribute
                        ));
                        continue;
                    }

                    // Make sure the username format is compatible with GitLab later on
                    if (($ldapUserNameSlugified = $slugifyLdapUsername->slugify($ldapUserName)) !== $ldapUserName) {
                        $this->logger?->warning(sprintf(
                            "User #%d [%s]: Username \"%s\" is incompatible with GitLab, changed to \"%s\".",
                            $n,
                            $ldapUserDn,
                            $ldapUserName,
                            $ldapUserNameSlugified
                        ));
                        $ldapUserName = $ldapUserNameSlugified;
                    }

                    if (!isset($ldapUser[$ldapUserMatchAttribute])) {
                        $this->logger?->error(sprintf(
                            "User #%d [%s]: Missing attribute \"%s\".",
                            $n,
                            $ldapUserDn,
                            $ldapUserMatchAttribute
                        ));
                        continue;
                    }

                    if (!is_array(
                        $ldapUser[$ldapUserMatchAttribute])
                        || !isset($ldapUser[$ldapUserMatchAttribute][0])
                        || !is_string($ldapUser[$ldapUserMatchAttribute][0])
                    ) {
                        $this->logger?->error(sprintf(
                            "User #%d [%s]: Invalid attribute \"%s\".",
                            $n,
                            $ldapUserDn,
                            $ldapUserMatchAttribute
                        ));
                        continue;
                    }

                    if ("" === ($ldapUserMatch = trim($ldapUser[$ldapUserMatchAttribute][0]))) {
                        $this->logger?->error(sprintf(
                            "User #%d [%s]: Empty attribute \"%s\".",
                            $n,
                            $ldapUserDn,
                            $ldapUserMatchAttribute
                        ));
                        continue;
                    }

                    if (!isset($ldapUser[$ldapNameAttribute])) {
                        $this->logger?->error(sprintf(
                            "User #%d [%s]: Missing attribute \"%s\".",
                            $n,
                            $ldapUserDn,
                            $ldapNameAttribute
                        ));
                        continue;
                    }

                    if (!is_array(
                        $ldapUser[$ldapNameAttribute])
                        || !isset($ldapUser[$ldapNameAttribute][0])
                        || !is_string($ldapUser[$ldapNameAttribute][0])
                    ) {
                        $this->logger?->error(sprintf(
                            "User #%d [%s]: Invalid attribute \"%s\".",
                            $n,
                            $ldapUserDn,
                            $ldapNameAttribute
                        ));
                        continue;
                    }

                    if ("" === ($ldapUserFullName = trim($ldapUser[$ldapNameAttribute][0]))) {
                        $this->logger?->error(sprintf(
                            "User #%d [%s]: Empty attribute \"%s\".",
                            $n,
                            $ldapUserDn,
                            $ldapNameAttribute
                        ));
                        continue;
                    }

                    if (!isset($ldapUser[$ldapEmailAttribute])) {
                        $this->logger?->error(sprintf(
                            "User #%d [%s]: Missing attribute \"%s\".",
                            $n,
                            $ldapUserDn,
                            $ldapEmailAttribute
                        ));
                        continue;
                    }

                    if (!is_array(
                        $ldapUser[$ldapEmailAttribute])
                        || !isset($ldapUser[$ldapEmailAttribute][0])
                        || !is_string($ldapUser[$ldapEmailAttribute][0])
                    ) {
                        $this->logger?->error(sprintf(
                            "User #%d [%s]: Invalid attribute \"%s\".",
                            $n,
                            $ldapUserDn,
                            $ldapEmailAttribute
                        ));
                        continue;
                    }

                    if ("" === ($ldapUserEmail = trim($ldapUser[$ldapEmailAttribute][0]))) {
                        $this->logger?->error(sprintf(
                            "User #%d [%s]: Empty attribute \"%s\".",
                            $n,
                            $ldapUserDn,
                            $ldapEmailAttribute
                        ));
                        continue;
                    }

                    if ($this->in_array_combined($ldapUserName, $config["gitlab"]["options"]["userNamesToIgnore"], $config["gitlab"]["options"]["userNamesToIgnoreRegex"])) {
                        $this->logger?->info(sprintf("User \"%s\" in ignore list.", $ldapUserName));
                        continue;
                    }

                    $this->logger?->info(sprintf("Found directory user \"%s\" [%s].", $ldapUserName, $ldapUserDn));
                    if (isset($users[$ldapUserName]) && is_array($users[$ldapUserName])) {
                        $this->logger?->warning(sprintf(
                            "Duplicate directory user \"%s\" [%s].",
                            $ldapUserName,
                            $ldapUserDn
                        ));
                        continue;
                    }

                    $users[$ldapUserName] = [
                        "dn"            => $ldapUserDn,
                        "username"      => $ldapUserName,
                        "userMatchId"   => $ldapUserMatch,
                        "fullName"      => $ldapUserFullName,
                        "email"         => $ldapUserEmail,
                        "isAdmin"       => false,
                        "isExternal"    => false,
                    ];
                }

                ksort($users);
                $this->logger?->notice(sprintf("%d directory user(s) recognised.", $usersNum = count($users)));
            } else {
                $this->logger?->warning("No directory users found.");
            }
        } else {
            $this->logger?->error("Directory users query failed.");
        }
        // >> Retrieve users

        // << Retrieve groups
        $ldapGroupsQueryBase = sprintf(
            "%s%s%s",
            $config["ldap"]["queries"]["groupDn"],
            is_string($config["ldap"]["queries"]["groupDn"]) && "" !== $config["ldap"]["queries"]["groupDn"] ? "," : "",
            $config["ldap"]["queries"]["baseDn"]
        );

        $ldapGroupsQueryAttributes = [
            $config["ldap"]["queries"]["groupUniqueAttribute"],
            $config["ldap"]["queries"]["groupMemberAttribute"],
        ];

        $this->logger?->debug("Retrieving groups.", [
            "base"          => $ldapGroupsQueryBase,
            "filter"        => $config["ldap"]["queries"]["groupFilter"],
            "attributes"    => $ldapGroupsQueryAttributes,
        ]);

        $ldapGroupsQuery = @ldap_search(
            $ldap,
            $ldapGroupsQueryBase,
            strval($config["ldap"]["queries"]["groupFilter"]),
            $ldapGroupsQueryAttributes
        );
        if (false === $ldapGroupsQuery || !($ldapGroupsQuery instanceof \LDAP\Result)) {
            throw new \RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
        }

        $ldapGroupAttribute         = is_string($config["ldap"]["queries"]["groupUniqueAttribute"])
            ? strtolower($config["ldap"]["queries"]["groupUniqueAttribute"])
            : "cn"
        ;
        $ldapGroupMemberAttribute   = is_string($config["ldap"]["queries"]["groupMemberAttribute"])
            ? strtolower($config["ldap"]["queries"]["groupMemberAttribute"])
            : "memberUid"
        ;

        $ldapGroups = @ldap_get_entries($ldap, $ldapGroupsQuery);
        if (is_array($ldapGroups)) {
            if (($ldapGroupsNum = count($ldapGroups)) >= 1) {
                $this->logger?->notice(sprintf("%d directory group(s) found.", $ldapGroupsNum));

                foreach ($ldapGroups as $i => $ldapGroup) {
                    if (!is_int($i)) {
                        continue;
                    }
                    $n = $i + 1;

                    if (!is_array($ldapGroup)) {
                        $this->logger?->error(sprintf("Group #%d: Not an array.", $n));
                        continue;
                    }

                    if (!isset($ldapGroup[$ldapGroupAttribute])) {
                        $this->logger?->error(sprintf("Group #%d: Missing attribute \"%s\".", $n, $ldapGroupAttribute));
                        continue;
                    }

                    if (!is_array(
                        $ldapGroup[$ldapGroupAttribute])
                        || !isset($ldapGroup[$ldapGroupAttribute][0])
                        || !is_string($ldapGroup[$ldapGroupAttribute][0])
                    ) {
                        $this->logger?->error(sprintf("Group #%d: Invalid attribute \"%s\".", $n, $ldapGroupAttribute));
                        continue;
                    }

                    if ("" === ($ldapGroupName = trim($ldapGroup[$ldapGroupAttribute][0]))) {
                        $this->logger?->error(sprintf("Group #%d: Empty attribute \"%s\".", $n, $ldapGroupAttribute));
                        continue;
                    }

                    if ($this->in_array_combined($ldapGroupName, $config["gitlab"]["options"]["groupNamesToIgnore"], $config["gitlab"]["options"]["groupNamesToIgnoreRegex"])) {
                        $this->logger?->info(sprintf("Group \"%s\" in ignore list.", $ldapGroupName));
                        continue;
                    }

                    $this->logger?->info(sprintf("Found directory group \"%s\".", $ldapGroupName));
                    if (isset($groups[$ldapGroupName])) {
                        $this->logger?->warning(sprintf("Duplicate directory group \"%s\".", $ldapGroupName));
                        continue;
                    }

                    $groups[$ldapGroupName] = [];

                    if (!isset($ldapGroup[$ldapGroupMemberAttribute])) {
                        $this->logger?->warning(sprintf(
                            "Group #%d: Missing attribute \"%s\". (Could also mean this group has no members.)",
                            $n,
                            $ldapGroupMemberAttribute
                        ));
                        continue;
                    }

                    if (!is_array($ldapGroup[$ldapGroupMemberAttribute])) {
                        $this->logger?->warning(sprintf(
                            "Group #%d: Invalid attribute \"%s\".",
                            $n,
                            $ldapGroupMemberAttribute
                        ));
                        continue;
                    }

                    if ($groupMembersAreAdmin = $this->in_array_combined(
                        $ldapGroupName,
                        $config["gitlab"]["options"]["groupNamesOfAdministrators"],
                        $config["gitlab"]["options"]["groupNamesOfAdministratorsRegex"])
                    ) {
                        $this->logger?->info(sprintf("Group \"%s\" members are administrators.", $ldapGroupName));
                    }

                    if ($groupMembersAreExternal = $this->in_array_combined(
                        $ldapGroupName,
                        $config["gitlab"]["options"]["groupNamesOfExternal"],
                        $config["gitlab"]["options"]["groupNamesOfExternalRegex"])
                    ) {
                        $this->logger?->info(sprintf("Group \"%s\" members are external.", $ldapGroupName));
                    }

                    // Retrieve group user memberships
                    foreach ($ldapGroup[$ldapGroupMemberAttribute] as $j => $ldapGroupMember) {
                        if (!is_int($j)) {
                            continue;
                        }
                        $o = $j + 1;

                        if (!is_string($ldapGroupMember)) {
                            $this->logger?->warning(sprintf(
                                "Group #%d / member #%d: Invalid member attribute \"%s\".",
                                $n,
                                $o,
                                $ldapGroupMemberAttribute
                            ));
                            continue;
                        }

                        if ("" === ($ldapGroupMemberName = trim($ldapGroupMember))) {
                            $this->logger?->warning(sprintf(
                                "Group #%d / member #%d: Empty member attribute \"%s\".",
                                $n,
                                $o,
                                $ldapGroupMemberAttribute
                            ));
                            continue;
                        }

                        $ldapUserMatchFound = false;
                        if ($this->in_array_i($ldapGroupMemberAttribute, ["memberUid"])) {
                            foreach ($users as $userName => $user) {
                                if (($ldapUserMatchAttribute === $ldapUserAttribute
                                    ? $userName
                                    : $user["userMatchId"]
                                ) === $ldapGroupMemberName) {
                                    $ldapGroupMemberName = $userName;
                                    $this->logger?->debug(sprintf(
                                        "Group #%d / member #%d: User member name \"%s\" matched to user name \"%s\".",
                                        $n,
                                        $o,
                                        $ldapGroupMemberName,
                                        $userName
                                    ));
                                    $ldapUserMatchFound = true;
                                    break;
                                }
                            }
                        } elseif ($this->in_array_i($ldapGroupMemberAttribute, ["member", "uniqueMember"])) {
                            foreach ($users as $userName => $user) {
                                if ($user["dn"] === $ldapGroupMemberName) {
                                    $ldapGroupMemberName = $userName;
                                    $this->logger?->debug(sprintf(
                                        "Group #%d / member #%d: User member name \"%s\" matched to user name \"%s\".",
                                        $n,
                                        $o,
                                        $ldapGroupMemberName,
                                        $userName
                                    ));
                                    $ldapUserMatchFound = true;
                                    break;
                                }
                            }
                        }

                        if (!$ldapUserMatchFound) {
                            $this->logger?->warning(sprintf(
                                "Group #%d / member #%d: No matching user name found for group member attribute \"%s\".",
                                $n,
                                $o,
                                $ldapGroupMemberAttribute
                            ));
                            continue;
                        }

                        if ($this->in_array_combined($ldapGroupMemberName, $config["gitlab"]["options"]["userNamesToIgnore"], $config["gitlab"]["options"]["userNamesToIgnoreRegex"])) {
                            $this->logger?->info(sprintf(
                                "Group #%d / member #%d: User \"%s\" in ignore list.",
                                $n,
                                $o,
                                $ldapGroupMemberName
                            ));
                            continue;
                        }

                        if (!isset($users[$ldapGroupMemberName]) || !is_array($users[$ldapGroupMemberName])) {
                            $this->logger?->warning(sprintf(
                                "Group #%d / member #%d: User not found \"%s\".",
                                $n,
                                $o,
                                $ldapGroupMemberName
                            ));
                            continue;
                        }

                        $this->logger?->info(sprintf(
                            "Found directory group \"%s\" member \"%s\".",
                            $ldapGroupName,
                            $ldapGroupMemberName
                        ));
                        if (isset($groups[$ldapGroupName][$ldapGroupMemberName])) {
                            $this->logger?->warning(sprintf(
                                "Duplicate directory group \"%s\" member \"%s\".",
                                $ldapGroupName,
                                $ldapGroupMemberName
                            ));
                            continue;
                        }

                        $groups[$ldapGroupName][] = $ldapGroupMemberName;

                        if ($groupMembersAreAdmin) {
                            $this->logger?->info(sprintf(
                                "Group #%d / member #%d: User \"%s\" is an administrator.",
                                $n,
                                $o,
                                $ldapGroupMemberName
                            ));
                            $users[$ldapGroupMemberName]["isAdmin"] = true;
                        }

                        if ($groupMembersAreExternal) {
                            $this->logger?->info(sprintf(
                                "Group #%d / member #%d: User \"%s\" is external.",
                                $n,
                                $o,
                                $ldapGroupMemberName
                            ));
                            $users[$ldapGroupMemberName]["isExternal"] = true;
                        }
                    }

                    $this->logger?->notice(sprintf(
                        "%d directory group \"%s\" member(s) recognised.",
                        count($groups[$ldapGroupName]),
                        $ldapGroupName
                    ));
                    sort($groups[$ldapGroupName]);
                }

                ksort($groups);
                $this->logger?->notice(sprintf("%d directory group(s) recognised.", $groupsNum = count($groups)));
            } else {
                $this->logger?->warning("No directory groups found.");
            }
        } else {
            $this->logger?->error("Directory groups query failed.");
        }
        // >> Retrieve groups

        // Disconnect
        $this->logger?->debug("LDAP: Unbinding");
        if (false === @ldap_unbind($ldap)) {
            throw new \RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
        }
        $ldap = null;

        $this->logger?->notice("LDAP connection closed.");
    }

    /**
     * Deploy users and groups to a GitLab instance.
     *
     * @param ConfigArray $config Validated configuration.
     * @param non-empty-string $gitLabInstance GitLab instance name.
     * @param ConfigGitLabArray $gitLabConfig GitLab instance configuration.
     * @param array<non-empty-string, LdapUserArray> $ldapUsers LDAP users.
     * @param non-negative-int $ldapUsersNum LDAP users count.
     * @param array<non-empty-string, non-empty-string[]> $ldapGroups LDAP groups.
     * @param non-negative-int $ldapGroupsNum LDAP groups count.
     */
    private function deployGitLabUsersAndGroups(
        array $config,
        string $gitLabInstance,
        array $gitLabConfig,
        array $ldapUsers,
        int $ldapUsersNum,
        array $ldapGroups,
        int $ldapGroupsNum
    ): void {
        $this->output?->writeln(sprintf("Deploying users and groups to GitLab instance \"%s\"...", $gitLabInstance));

        $slugifyGitLabName = new Slugify([
            "regexp"        => "/([^A-Za-z0-9_\.\(\)\- ])+/",
            "separator"     => "",
            "lowercase"     => false,
            "trim"          => true,
        ]);

        $slugifyGitLabPath = new Slugify([
            "regexp"        => "/([^A-Za-z0-9_\.\-])+/",
            "separator"     => "-",
            "lowercase"     => true,
            "trim"          => true,
        ]);

        // Convert LDAP group names into a format safe for GitLab's restrictions
        // Removes prefix if groupNamesPrefix is defined. Skips groups without prefix.
        $ldapGroupsSafe = [];
        foreach ($ldapGroups as $ldapGroupName => $ldapGroupMembers) {
            $ldapGroupNameNoPrefix = $ldapGroupName;
            if($config["gitlab"]["options"]["groupNamesPrefix"] !== null) {
                if(str_starts_with($ldapGroupNameNoPrefix, $config["gitlab"]["options"]["groupNamesPrefix"])) {
                    $ldapGroupNameNoPrefix = substr($ldapGroupName, strlen($config["gitlab"]["options"]["groupNamesPrefix"]));
                } else {
                    $this->logger?->info(sprintf("Group \"%s\" does not begin with gitlab->options->groupNamesPrefix, ignoring for group creation and membership.", $ldapGroupName));
                    continue;
                }
            }

            $ldapGroupsSafe[$slugifyGitLabName->slugify($ldapGroupNameNoPrefix)] = $ldapGroupMembers;
        }

        // Connect
        $this->logger?->notice("Establishing GitLab connection.", [
            "instance"  => $gitLabInstance,
            "url"       => $gitLabConfig["url"],
        ]);

        $this->logger?->debug("GitLab: Connecting");
        $gitLab = new \Gitlab\Client();
        $gitLab->setUrl($gitLabConfig["url"]);
        $gitLab->authenticate($gitLabConfig["token"], \Gitlab\Client::AUTH_HTTP_TOKEN);

        // << Handle users
        /**
         * @var array{
         *  found: array<int, non-empty-string>,
         *  foundNum: non-negative-int,
         *  new: array<int, non-empty-string>,
         *  newNum: non-negative-int,
         *  extra: array<int, non-empty-string>,
         *  extraNum: non-negative-int,
         *  update: array<int, non-empty-string>,
         *  updateNum: non-negative-int,
         * } $usersSync
         */
        $usersSync = [
            "found"     => [],  // All existing GitLab users
            "foundNum"  => 0,
            "new"       => [],  // Users in LDAP but not GitLab
            "newNum"    => 0,
            "extra"     => [],  // Users in GitLab but not LDAP
            "extraNum"  => 0,
            "update"    => [],  // Users in both LDAP and GitLab
            "updateNum" => 0,
        ];

        // Find all existing GitLab users
        $this->logger?->notice("Finding all existing GitLab users...");
        $p = 0;

        while (is_array($gitLabUsers = $gitLab->users()->all([
            "page" => ++$p,
            "per_page" => 100,
        ])) && [] !== $gitLabUsers) {
            /** @var array<int, GitLabUserArray> $gitLabUsers */
            foreach ($gitLabUsers as $i => $gitLabUser) {
                $n = $i + 1;

                if (!is_array($gitLabUser)) {
                    $this->logger?->error(sprintf("User #%d: Not an array.", $n));
                    continue;
                }

                if (!isset($gitLabUser["id"])) {
                    $this->logger?->error(sprintf("User #%d: Missing ID.", $n));
                    continue;
                }

                $gitLabUserId = intval($gitLabUser["id"]);
                if ($gitLabUserId < 1) {
                    $this->logger?->error(sprintf("User #%d: Empty ID.", $n));
                    continue;
                }

                if (!isset($gitLabUser["username"])) {
                    $this->logger?->error(sprintf("User #%d: Missing user name.", $n));
                    continue;
                }

                if ("" === ($gitLabUserName = trim($gitLabUser["username"]))) {
                    $this->logger?->error(sprintf("User #%d: Empty user name.", $n));
                    continue;
                }

                if ($this->in_array_i($gitLabUserName, self::getBuiltInUserNames())) {
                    $this->logger?->info(sprintf("User \"%s\" in built in ignore list.", $gitLabUserName));
                    continue;
                }

                $this->logger?->info(sprintf("Found GitLab user #%d \"%s\".", $gitLabUserId, $gitLabUserName));
                if (
                    isset($usersSync["found"][$gitLabUserId])
                    || $this->in_array_i($gitLabUserName, $usersSync["found"])
                ) {
                    $this->logger?->warning(sprintf(
                        "Duplicate GitLab user #%d \"%s\".",
                        $gitLabUserId,
                        $gitLabUserName
                    ));
                    continue;
                }

                $usersSync["found"][$gitLabUserId] = $gitLabUserName;
            }
        }

        asort($usersSync["found"]);
        $this->logger?->notice(sprintf(
            "%d GitLab user(s) found.",
            $usersSync["foundNum"] = count($usersSync["found"])
        ));

        // Create directory users of which don't exist in GitLab
        $this->logger?->notice("Creating directory users of which don't exist in GitLab...");
        foreach ($ldapUsers as $ldapUserName => $ldapUserDetails) {
            if ($this->in_array_i($ldapUserName, self::getBuiltInUserNames())) {
                $this->logger?->info(sprintf("User \"%s\" in built in ignore list.", $ldapUserName));
                continue;
            }

            if ($this->in_array_combined($ldapUserName, $config["gitlab"]["options"]["userNamesToIgnore"], $config["gitlab"]["options"]["userNamesToIgnoreRegex"])) {
                $this->logger?->info(sprintf("User \"%s\" in ignore list.", $ldapUserName));
                continue;
            }

            $gitLabUserName = trim($ldapUserName);
            if ($this->in_array_i($gitLabUserName, $usersSync["found"])) {
                continue;
            }

            if (!isset($ldapUserDetails["dn"]) || !is_string($ldapUserDetails["dn"])) {
                $this->logger?->error(sprintf("User \"%s\": Missing distinguished name.", $ldapUserName));
                continue;
            }

            if ("" === ($ldapUserDn = trim(strval($ldapUserDetails["dn"])))) {
                $this->logger?->error(sprintf("User \"%s\": Empty distinguished name.", $ldapUserName));
                continue;
            }

            if (!isset($ldapUserDetails["email"]) || !is_string($ldapUserDetails["email"])) {
                $this->logger?->error(sprintf("User \"%s\": Missing email address.", $ldapUserName));
                continue;
            }

            if ("" === ($ldapUserEmail = trim(strval($ldapUserDetails["email"])))) {
                $this->logger?->error(sprintf("User \"%s\": Empty email address.", $ldapUserName));
                continue;
            }

            $this->logger?->info(sprintf("Creating GitLab user \"%s\" [%s].", $gitLabUserName, $ldapUserDn));
            /** @var GitLabUserArray|null $gitLabUser */
            $gitLabUser = null;

            $gitLabUserPassword = $this->generateRandomPassword(12);
            $this->logger?->debug(sprintf(
                "Password for GitLab user \"%s\" [%s] will be: %s",
                $gitLabUserName,
                $ldapUserDn,
                $gitLabUserPassword
            ));

            try {
                /** @var GitLabUserArray|null $gitLabUser */
                !$this->dryRun ? ($gitLabUser = $gitLab->users()->create($ldapUserEmail, $gitLabUserPassword, [
                    "username"          => $gitLabUserName,
                    "reset_password"    => false,
                    "name"              => $ldapUserDetails["fullName"],
                    "extern_uid"        => $ldapUserDn,
                    "provider"          => $gitLabConfig["ldapServerName"],
                    "email"             => $ldapUserEmail,
                    "admin"             => $ldapUserDetails["isAdmin"],
                    "can_create_group"  => $ldapUserDetails["isAdmin"],
                    "skip_confirmation" => true,
                    "external"          => $ldapUserDetails["isExternal"],
                ])) : $this->logger?->warning("Operation skipped due to dry run.");
            } catch (\Exception $e) {
                // Permit continue when user email address already used by another account
                if ("Email has already been taken" === $e->getMessage()) {
                    $this->logger?->error(sprintf(
                        "GitLab user \"%s\" [%s] was not created, email address already used by another account.",
                        $gitLabUserName,
                        $ldapUserDn
                    ));
                }

                if ($this->continueOnFail) {
                    $this->gitLabApiCoolDown();
                    continue;
                }

                throw $e;
            }

            $gitLabUserId = (is_array($gitLabUser) && isset($gitLabUser["id"]) && is_int($gitLabUser["id"]))
                ? $gitLabUser["id"]
                : sprintf("dry:%s", $ldapUserDn)
            ;
            $usersSync["new"][$gitLabUserId] = $gitLabUserName;

            $this->gitLabApiCoolDown();
        }

        asort($usersSync["new"]);
        $this->logger?->notice(sprintf("%d GitLab user(s) created.", $usersSync["newNum"] = count($usersSync["new"])));

        // Synchronise users of between GitLab and the directory
        $this->logger?->notice("Synchronising users of between GitLab and the directory...");
        foreach ($usersSync["found"] as $gitLabUserId => $gitLabUserName) {
            $gitLabUser = $gitLab->users()->show($gitLabUserId);
            if (!is_array($gitLabUser) || [] === $gitLabUser) {
                $this->logger?->error(sprintf(
                    "GitLab user #%d \"%s\" could not be retrieved.",
                    $gitLabUserId,
                    $gitLabUserName
                ));
                continue;
            }

            if (isset($gitLabUser["bot"]) && true === $gitLabUser["bot"]) {
                $this->logger?->info(sprintf(
                    "GitLab user #%d \"%s\" is a bot, ignoring.",
                    $gitLabUserId,
                    $gitLabUserName
                ));
                continue;
            }

            if (isset($usersSync["new"][$gitLabUserId]) && "" !== $usersSync["new"][$gitLabUserId]) {
                continue;
            }

            if ($this->in_array_i($gitLabUserName, self::getBuiltInUserNames())) {
                $this->logger?->info(sprintf("User \"%s\" in built in ignore list.", $gitLabUserName));
                continue;
            }

            if ($this->in_array_combined($gitLabUserName, $config["gitlab"]["options"]["userNamesToIgnore"], $config["gitlab"]["options"]["userNamesToIgnoreRegex"])) {
                $this->logger?->info(sprintf("User \"%s\" in ignore list.", $gitLabUserName));
                continue;
            }

            if (
                isset($ldapUsers[$gitLabUserName])
                && is_array($ldapUsers[$gitLabUserName])
                && [] !== $ldapUsers[$gitLabUserName]
            ) {
                // User exists in directory: Update
                if ("ldap_blocked" === $gitLabUser["state"]) {
                    $this->logger?->warning(sprintf(
                        "GitLab user #%d \"%s\" is LDAP blocked, can't update.",
                        $gitLabUserId,
                        $gitLabUserName
                    ));
                    continue;
                }

                if ("blocked" === $gitLabUser["state"]) {
                    $this->logger?->info(sprintf("Enabling GitLab user #%d \"%s\".", $gitLabUserId, $gitLabUserName));
                    /** @var GitLabUserArray|null $gitLabUser */
                    !$this->dryRun
                        ? ($gitLabUser = $gitLab->users()->unblock($gitLabUserId))
                        : $this->logger?->warning("Operation skipped due to dry run.")
                    ;
                }

                $this->logger?->info(sprintf("Updating GitLab user #%d \"%s\".", $gitLabUserId, $gitLabUserName));
                $ldapUserDetails = $ldapUsers[$gitLabUserName];

                if (!isset($ldapUserDetails["dn"]) || !is_string($ldapUserDetails["dn"])) {
                    $this->logger?->error(sprintf(
                        "GitLab user #%d \"%s\": Missing distinguished name.",
                        $gitLabUserId,
                        $gitLabUserName
                    ));
                    continue;
                }

                if ("" === ($ldapUserDn = trim(strval($ldapUserDetails["dn"])))) {
                    $this->logger?->error(sprintf(
                        "GitLab user #%d \"%s\": Empty distinguished name.",
                        $gitLabUserId,
                        $gitLabUserName
                    ));
                    continue;
                }

                /** @var GitLabUserArray|null $gitLabUser */
                !$this->dryRun ? ($gitLabUser = $gitLab->users()->update($gitLabUserId, [
                    // "username"          => $gitLabUserName,
                    // No point updating that. ^
                    // If the UID changes so will that bit of the DN anyway, so this can't be detected with a custom
                    // attribute containing the GitLab user ID written back to user's LDAP object.
                    "reset_password"    => false,
                    "name"              => $ldapUserDetails["fullName"],
                    "extern_uid"        => $ldapUserDn,
                    "provider"          => $gitLabConfig["ldapServerName"],
                    "email"             => $ldapUserDetails["email"],
                    "admin"             => $ldapUserDetails["isAdmin"],
                    "can_create_group"  => $ldapUserDetails["isAdmin"],
                    "skip_confirmation" => true,
                    "external"          => $ldapUserDetails["isExternal"],
                ])) : $this->logger?->warning("Operation skipped due to dry run.");

                $usersSync["update"][$gitLabUserId] = $gitLabUserName;
            } else {
                // User does not exist in directory: Disable
                if (in_array($gitLabUser["state"], ["blocked", "ldap_blocked"], true)) {
                    $this->logger?->debug(sprintf(
                        "GitLab user #%d \"%s\" already disabled.",
                        $gitLabUserId,
                        $gitLabUserName
                    ));
                    continue;
                }

                $this->logger?->warning(sprintf("Disabling GitLab user #%d \"%s\".", $gitLabUserId, $gitLabUserName));
                /** @var GitLabUserArray|null $gitLabUser */
                !$this->dryRun
                    ? ($gitLabUser = $gitLab->users()->block($gitLabUserId))
                    : $this->logger?->warning("Operation skipped due to dry run.")
                ;
                /** @var GitLabUserArray|null $gitLabUser */
                !$this->dryRun ? ($gitLabUser = $gitLab->users()->update($gitLabUserId, [
                    "admin"             => false,
                    "can_create_group"  => false,
                    "external"          => true,
                ])) : $this->logger?->warning("Operation skipped due to dry run.");

                $usersSync["extra"][$gitLabUserId] = $gitLabUserName;
            }

            $this->gitLabApiCoolDown();
        }

        asort($usersSync["update"]);
        $this->logger?->notice(sprintf(
            "%d GitLab user(s) updated.",
            $usersSync["updateNum"] = count($usersSync["update"])
        ));
        asort($usersSync["extra"]);
        $this->logger?->notice(sprintf(
            "%d GitLab user(s) disabled.",
            $usersSync["extraNum"] = count($usersSync["extra"])
        ));
        // >> Handle users

        // << Handle groups
        /**
         * @var array{
         *  found: array<int, non-empty-string>,
         *  foundNum: non-negative-int,
         *  new: array<int, non-empty-string>,
         *  newNum: non-negative-int,
         *  extra: array<int, non-empty-string>,
         *  extraNum: non-negative-int,
         *  update: array<int, non-empty-string>,
         *  updateNum: non-negative-int,
         * } $groupsSync
         */
        $groupsSync = [
            "found"     => [],  // All existing GitLab groups
            "foundNum"  => 0,
            "new"       => [],  // Groups in LDAP but not GitLab
            "newNum"    => 0,
            "extra"     => [],  // Groups in GitLab but not LDAP
            "extraNum"  => 0,
            "update"    => [],  // Groups in both LDAP and GitLab
            "updateNum" => 0,
        ];

        // Find all existing GitLab groups
        $this->logger?->notice("Finding all existing GitLab groups...");
        $p = 0;

        while (is_array($gitLabGroups = $gitLab->groups()->all([
            "page" => ++$p,
            "per_page" => 100,
            "all_available" => true,
        ])) && [] !== $gitLabGroups) {
            /** @var array<int, GitLabGroupArray> $gitLabGroups */
            foreach ($gitLabGroups as $i => $gitLabGroup) {
                $n = $i + 1;

                if (!is_array($gitLabGroup)) {
                    $this->logger?->error(sprintf("Group #%d: Not an array.", $n));
                    continue;
                }

                if (!isset($gitLabGroup["id"])) {
                    $this->logger?->error(sprintf("Group #%d: Missing ID.", $n));
                    continue;
                }

                $gitLabGroupId = intval($gitLabGroup["id"]);
                if ($gitLabGroupId < 1) {
                    $this->logger?->error(sprintf("Group #%d: Empty ID.", $n));
                    continue;
                }

                if (!isset($gitLabGroup["name"])) {
                    $this->logger?->error(sprintf("Group #%d: Missing name.", $n));
                    continue;
                }

                if ("" === ($gitLabGroupName = trim($gitLabGroup["name"]))) {
                    $this->logger?->error(sprintf("Group #%d: Empty name.", $n));
                    continue;
                }

                if ("" === ($gitLabGroupPath = trim($gitLabGroup["path"]))) {
                    $this->logger?->error(sprintf("Group #%d: Empty path.", $n));
                    continue;
                }

                if ($this->in_array_i($gitLabGroupName, self::getBuiltInGroups())) {
                    $this->logger?->info(sprintf("Group \"%s\" in built-in ignore list.", $gitLabGroupName));
                    continue;
                }

                if ($this->in_array_i($gitLabGroupName, self::getReservedGroups())) {
                    $this->logger?->warning(sprintf("Group \"%s\" in built-in reserved list.", $gitLabGroupName));
                    continue;
                }

                $this->logger?->info(sprintf(
                    "Found GitLab group #%d \"%s\" [%s].",
                    $gitLabGroupId,
                    $gitLabGroupName,
                    $gitLabGroupPath
                ));
                if (
                    isset($groupsSync["found"][$gitLabGroupId])
                    || $this->in_array_i($gitLabGroupName, $groupsSync["found"])
                ) {
                    $this->logger?->warning(sprintf(
                        "Duplicate GitLab group %d \"%s\" [%s].",
                        $gitLabGroupId,
                        $gitLabGroupName,
                        $gitLabGroupPath
                    ));
                    continue;
                }

                $groupsSync["found"][$gitLabGroupId] = $gitLabGroupName;
            }
        }

        asort($groupsSync["found"]);
        $this->logger?->notice(sprintf(
            "%d GitLab group(s) found.",
            $groupsSync["foundNum"] = count($groupsSync["found"])
        ));

        // Create directory groups of which don't exist in GitLab
        $this->logger?->notice("Creating directory groups of which don't exist in GitLab...");
        foreach ($ldapGroupsSafe as $ldapGroupName => $ldapGroupMembers) {
            if ($this->in_array_i($ldapGroupName, self::getBuiltInGroups())) {
                $this->logger?->info(sprintf("Group \"%s\" in built-in ignore list.", $ldapGroupName));
                continue;
            }

            if ($this->in_array_i($ldapGroupName, self::getReservedGroups())) {
                $this->logger?->warning(sprintf("Group \"%s\" in built-in reserved list.", $ldapGroupName));
                continue;
            }

            $ldapGroupNameWithPrefix = $ldapGroupName;
            if($config["gitlab"]["options"]["groupNamesPrefix"] !== null) {
                $ldapGroupNameWithPrefix = $config["gitlab"]["options"]["groupNamesPrefix"] . $ldapGroupNameWithPrefix;
            }

            if ($this->in_array_combined($ldapGroupNameWithPrefix, $config["gitlab"]["options"]["groupNamesToIgnore"], $config["gitlab"]["options"]["groupNamesToIgnoreRegex"])) {
                $this->logger?->info(sprintf("Group \"%s\" in ignore list.", $ldapGroupNameWithPrefix));
                continue;
            }

            $gitLabGroupName = $slugifyGitLabName->slugify($ldapGroupName);
            $gitLabGroupPath = $slugifyGitLabPath->slugify($ldapGroupName);
            if ($this->in_array_i($gitLabGroupName, $groupsSync["found"])) {
                continue;
            }

            if (
                (!is_array($ldapGroupMembers) || [] === $ldapGroupMembers)
                && !$config["gitlab"]["options"]["createEmptyGroups"]
            ) {
                $this->logger?->warning(sprintf(
                    "Not creating GitLab group \"%s\" [%s]: No members in directory group, or config gitlab->options->createEmptyGroups is disabled.",
                    $gitLabGroupName,
                    $gitLabGroupPath
                ));
                continue;
            }

            $this->logger?->info(sprintf("Creating GitLab group \"%s\" [%s].", $gitLabGroupName, $gitLabGroupPath));
            $gitLabGroup = null;

            /** @var GitLabGroupArray|null $gitLabUser */
            !$this->dryRun
                ? ($gitLabGroup = $gitLab->groups()->create($gitLabGroupName, $gitLabGroupPath))
                : $this->logger?->warning("Operation skipped due to dry run.")
            ;

            $gitLabGroupId = (is_array($gitLabGroup) && isset($gitLabGroup["id"]) && is_int($gitLabGroup["id"]))
                ? $gitLabGroup["id"]
                : sprintf("dry:%s", $gitLabGroupPath)
            ;
            $groupsSync["new"][$gitLabGroupId] = $gitLabGroupName;

            $this->gitLabApiCoolDown();
        }

        asort($groupsSync["new"]);
        $this->logger?->notice(sprintf(
            "%d GitLab group(s) created.",
            $groupsSync["newNum"] = count($groupsSync["new"])
        ));

        // Delete GitLab groups of which don't exist in directory
        $this->logger?->notice("Deleting GitLab groups of which don't exist in directory...");
        foreach ($groupsSync["found"] as $gitLabGroupId => $gitLabGroupName) {
            if ($this->in_array_i($gitLabGroupName, self::getBuiltInGroups())) {
                $this->logger?->info(sprintf("Group \"%s\" in built-in ignore list.", $gitLabGroupName));
                continue;
            }

            if ($this->in_array_i($gitLabGroupName, self::getReservedGroups())) {
                $this->logger?->warning(sprintf("Group \"%s\" in built-in reserved list.", $gitLabGroupName));
                continue;
            }

            $gitLabGroupNameWithPrefix = $gitLabGroupName;
            if($config["gitlab"]["options"]["groupNamesPrefix"] !== null) {
                $gitLabGroupNameWithPrefix = $config["gitlab"]["options"]["groupNamesPrefix"] . $gitLabGroupNameWithPrefix;
            }

            if ($this->in_array_combined($gitLabGroupNameWithPrefix, $config["gitlab"]["options"]["groupNamesToIgnore"], $config["gitlab"]["options"]["groupNamesToIgnoreRegex"])) {
                $this->logger?->info(sprintf("Group \"%s\" in ignore list.", $gitLabGroupNameWithPrefix));
                continue;
            }

            if (!$this->array_key_exists_i($gitLabGroupName, $ldapGroupsSafe)) {
                continue;
            }
            $ldapGroupMembers = $ldapGroupsSafe[$gitLabGroupName];

            $gitLabGroupPath = $slugifyGitLabPath->slugify($gitLabGroupName);
            if (
                (is_array($ldapGroupMembers) && [] !== $ldapGroupMembers)
                || !$config["gitlab"]["options"]["deleteExtraGroups"]
            ) {
                $this->logger?->info(sprintf(
                    "Not deleting GitLab group #%d \"%s\" [%s]: Has members in directory group, or config gitlab->options->deleteExtraGroups is disabled.",
                    $gitLabGroupId,
                    $gitLabGroupName,
                    $gitLabGroupPath
                ));
                continue;
            }

            if (
                is_array($gitLabGroupProjects = $gitLab->groups()->projects($gitLabGroupId))
                && ($gitLabGroupProjectsNum = count($gitLabGroupProjects)) >= 1
            ) {
                $this->logger?->info(sprintf(
                    "Not deleting GitLab group #%d \"%s\" [%s]: It contains %d project(s).",
                    $gitLabGroupId,
                    $gitLabGroupName,
                    $gitLabGroupPath,
                    $gitLabGroupProjectsNum
                ));
                continue;
            }

            if (
                is_array($gitLabGroupSubGroups = $gitLab->groups()->subgroups($gitLabGroupId))
                && ($gitLabGroupSubGroupsNum = count($gitLabGroupSubGroups)) >= 1
            ) {
                $this->logger?->info(sprintf(
                    "Not deleting GitLab group #%d \"%s\" [%s]: It contains %d subgroup(s).",
                    $gitLabGroupId,
                    $gitLabGroupName,
                    $gitLabGroupPath,
                    $gitLabGroupSubGroupsNum
                ));
                continue;
            }

            $this->logger?->warning(sprintf(
                "Deleting GitLab group #%d \"%s\" [%s].",
                $gitLabGroupId,
                $gitLabGroupName,
                $gitLabGroupPath
            ));
            $gitLabGroup = null;

            /** @var GitLabGroupArray|null $gitLabUser */
            !$this->dryRun
                ? ($gitLabGroup = $gitLab->groups()->remove($gitLabGroupId))
                : $this->logger?->warning("Operation skipped due to dry run.")
            ;

            $groupsSync["extra"][$gitLabGroupId] = $gitLabGroupName;

            $this->gitLabApiCoolDown();
        }

        asort($groupsSync["extra"]);
        $this->logger?->notice(sprintf(
            "%d GitLab group(s) deleted.",
            $groupsSync["extraNum"] = count($groupsSync["extra"])
        ));

        // Update groups of which were already in both GitLab and the directory
        $this->logger?->notice("Updating groups of which were already in both GitLab and the directory...");
        foreach ($groupsSync["found"] as $gitLabGroupId => $gitLabGroupName) {
            if (
                (isset($groupsSync["new"][$gitLabGroupId]) && "" !== $groupsSync["new"][$gitLabGroupId])
                || (isset($groupsSync["extra"][$gitLabGroupId]) && "" !== $groupsSync["extra"][$gitLabGroupId])
            ) {
                continue;
            }

            if ($this->in_array_i($gitLabGroupName, self::getBuiltInGroups())) {
                $this->logger?->info(sprintf("Group \"%s\" in built-in ignore list.", $gitLabGroupName));
                continue;
            }

            if ($this->in_array_i($gitLabGroupName, self::getReservedGroups())) {
                $this->logger?->warning(sprintf("Group \"%s\" in built-in reserved list.", $gitLabGroupName));
                continue;
            }

            $gitLabGroupNameWithPrefix = $gitLabGroupName;
            if($config["gitlab"]["options"]["groupNamesPrefix"] !== null) {
                $gitLabGroupNameWithPrefix = $config["gitlab"]["options"]["groupNamesPrefix"] . $gitLabGroupNameWithPrefix;
            }
            
            if ($this->in_array_combined($gitLabGroupNameWithPrefix, $config["gitlab"]["options"]["groupNamesToIgnore"], $config["gitlab"]["options"]["groupNamesToIgnoreRegex"])) {
                $this->logger?->info(sprintf("Group \"%s\" in ignore list.", $gitLabGroupNameWithPrefix));
                continue;
            }

            $gitLabGroupPath = $slugifyGitLabPath->slugify($gitLabGroupName);

            $this->logger?->info(sprintf(
                "Updating GitLab group #%d \"%s\" [%s].",
                $gitLabGroupId,
                $gitLabGroupName,
                $gitLabGroupPath
            ));
            $gitLabGroup = null;

            if (!isset($ldapGroupsSafe[$gitLabGroupName]) || !is_array($ldapGroupsSafe[$gitLabGroupName])) {
                $this->logger?->info(sprintf("GitLab group \"%s\" has no LDAP details available.", $gitLabGroupName));
                continue;
            }
            $ldapGroupMembers = $ldapGroupsSafe[$gitLabGroupName];

            /** @var GitLabGroupArray|null $gitLabUser */
            /*
            !$this->dryRun ? ($gitLabGroup = $gitLab->groups()->update($gitLabGroupId, [
                // "name"              => $gitLabGroupName,
                // No point updating that. ^
                // If the CN changes so will that bit of the DN anyway, so this can't be detected with a custom
                // attribute containing the GitLab group ID written back to group's LDAP object.
                "path"              => $gitLabGroupPath,
            ])) : $this->logger?->warning("Operation skipped due to dry run.");
             */

            $groupsSync["update"][$gitLabGroupId] = $gitLabGroupName;

            /* Not required until group updates can be detected as per above.
            $this->gitLabApiCoolDown();
             */
        }

        asort($groupsSync["update"]);
        $this->logger?->notice(sprintf(
            "%d GitLab group(s) updated.",
            $groupsSync["updateNum"] = count($groupsSync["update"])
        ));
        // >> Handle groups

        // << Handle group memberships
        /** @var array<int, string> $usersToSyncMembership */
        $usersToSyncMembership  = ($usersSync["found"] + $usersSync["new"] + $usersSync["update"]);
        asort($usersToSyncMembership);
        /** @var array<int, string> $groupsToSyncMembership */
        $groupsToSyncMembership = ($groupsSync["found"] + $groupsSync["new"] + $groupsSync["update"]);
        asort($groupsToSyncMembership);

        $this->logger?->notice("Synchronising GitLab group members with directory group members...");
        foreach ($groupsToSyncMembership as $gitLabGroupId => $gitLabGroupName) {
            if ($this->in_array_i($gitLabGroupName, self::getBuiltInGroups())) {
                $this->logger?->info(sprintf("Group \"%s\" in built-in ignore list.", $gitLabGroupName));
                continue;
            }

            if ($this->in_array_i($gitLabGroupName, self::getReservedGroups())) {
                $this->logger?->warning(sprintf("Group \"%s\" in built-in reserved list.", $gitLabGroupName));
                continue;
            }

            $gitLabGroupNameWithPrefix = $gitLabGroupName;
            if($config["gitlab"]["options"]["groupNamesPrefix"] !== null) {
                $gitLabGroupNameWithPrefix = $config["gitlab"]["options"]["groupNamesPrefix"] . $gitLabGroupNameWithPrefix;
            }

            if ($this->in_array_combined($gitLabGroupNameWithPrefix, $config["gitlab"]["options"]["groupNamesToIgnore"], $config["gitlab"]["options"]["groupNamesToIgnoreRegex"])) {
                $this->logger?->info(sprintf("Group \"%s\" in ignore list.", $gitLabGroupNameWithPrefix));
                continue;
            }

            $gitLabGroupPath = $slugifyGitLabPath->slugify($gitLabGroupName);

            $membersOfThisGroup = [];
            foreach ($usersToSyncMembership as $gitLabUserId => $gitLabUserName) {
                if (!isset($ldapGroupsSafe[$gitLabGroupName]) || !is_array($ldapGroupsSafe[$gitLabGroupName])) {
                    $this->logger?->warning(sprintf(
                        "Group \"%s\" doesn't appear to exist at path \"%s\". (Is this a sub-group? Sub-groups are not supported yet.)",
                        $gitLabGroupName,
                        $gitLabGroupPath
                    ));
                    continue;
                }

                if (!$this->in_array_i($gitLabUserName, $ldapGroupsSafe[$gitLabGroupName])) {
                    continue;
                }

                $membersOfThisGroup[$gitLabUserId] = $gitLabUserName;
            }
            asort($membersOfThisGroup);
            $this->logger?->notice(sprintf(
                "Synchronising %d member(s) for group #%d \"%s\" [%s]...",
                ($membersOfThisGroupNum = count($membersOfThisGroup)),
                $gitLabGroupId,
                $gitLabGroupName,
                $gitLabGroupPath
            ));

            /**
             * @var array{
             *  found: array<int, string>,
             *  foundNum: non-negative-int,
             *  new: array<int, string>,
             *  newNum: int,
             *  extra: array<int, string>,
             *  extraNum: int,
             *  update: array<int, string>,
             *  updateNum: int,
             * } $userGroupMembersSync
             */
            $userGroupMembersSync = [
                "found"     => [],
                "foundNum"  => 0,
                "new"       => [],
                "newNum"    => 0,
                "extra"     => [],
                "extraNum"  => 0,
                "update"    => [],
                "updateNum" => 0,
            ];

            // Find existing group members
            $this->logger?->notice("Finding existing group members...");
            $p = 0;

            while (is_array($gitLabUsers = $gitLab->groups()->members($gitLabGroupId, [
                "page" => ++$p,
                "per_page" => 100,
            ])) && [] !== $gitLabUsers) {
                /** @var array<int, GitLabUserArray> $gitLabUsers */
                foreach ($gitLabUsers as $i => $gitLabUser) {
                    $n = $i + 1;

                    if (!is_array($gitLabUser)) {
                        $this->logger?->error(sprintf("Group member #%d: Not an array.", $n));
                        continue;
                    }

                    if (!isset($gitLabUser["id"])) {
                        $this->logger?->error(sprintf("Group member #%d: Missing ID.", $n));
                        continue;
                    }

                    $gitLabUserId = intval($gitLabUser["id"]);
                    if ($gitLabUserId < 1) {
                        $this->logger?->error(sprintf("Group member #%d: Empty ID.", $n));
                        continue;
                    }

                    if (!isset($gitLabUser["username"])) {
                        $this->logger?->error(sprintf("Group member #%d: Missing user name.", $n));
                        continue;
                    }

                    if ("" === ($gitLabUserName = trim($gitLabUser["username"]))) {
                        $this->logger?->error(sprintf("Group member #%d: Empty user name.", $n));
                        continue;
                    }

                    if ($this->in_array_i($gitLabUserName, self::getBuiltInUserNames())) {
                        $this->logger?->info(sprintf("User \"%s\" in built in ignore list.", $gitLabUserName));
                        continue;
                    }

                    $gitLabGroupNameWithPrefix = $gitLabGroupName;
                    if($config["gitlab"]["options"]["groupNamesPrefix"] !== null) {
                        $gitLabGroupNameWithPrefix = $config["gitlab"]["options"]["groupNamesPrefix"] . $gitLabGroupNameWithPrefix;
                    }

                    if ($this->in_array_combined($gitLabGroupNameWithPrefix, $config["gitlab"]["options"]["userNamesToIgnore"], $config["gitlab"]["options"]["userNamesToIgnoreRegex"])) {
                        $this->logger?->info(sprintf("User \"%s\" in ignore list.", $gitLabGroupNameWithPrefix));
                        continue;
                    }

                    $this->logger?->info(sprintf(
                        "Found GitLab group member #%d \"%s\".",
                        $gitLabUserId,
                        $gitLabUserName
                    ));
                    if (
                        isset($userGroupMembersSync["found"][$gitLabUserId])
                        || $this->in_array_i($gitLabUserName, $userGroupMembersSync["found"])
                    ) {
                        $this->logger?->warning(sprintf(
                            "Duplicate GitLab group member #%d \"%s\".",
                            $gitLabUserId,
                            $gitLabUserName
                        ));
                        continue;
                    }

                    $userGroupMembersSync["found"][$gitLabUserId] = $gitLabUserName;
                }
            }

            asort($userGroupMembersSync["found"]);
            $this->logger?->notice(sprintf(
                "%d GitLab group \"%s\" [%s] member(s) found.",
                $userGroupMembersSync["foundNum"] = count($userGroupMembersSync["found"]),
                $gitLabGroupName,
                $gitLabGroupPath
            ));

            // Add missing group members
            $this->logger?->notice("Adding missing group members...");
            foreach ($membersOfThisGroup as $gitLabUserId => $gitLabUserName) {
                if (
                    isset($userGroupMembersSync["found"][$gitLabUserId])
                    && $userGroupMembersSync["found"][$gitLabUserId] === $gitLabUserName
                ) {
                    continue;
                }

                if (
                    !isset($membersOfThisGroup[$gitLabUserId])
                    || $membersOfThisGroup[$gitLabUserId] !== $gitLabUserName
                ) {
                    continue;
                }

                $this->logger?->info(sprintf(
                    "Adding user #%d \"%s\" to group #%d \"%s\" [%s].",
                    $gitLabUserId,
                    $gitLabUserName,
                    $gitLabGroupId,
                    $gitLabGroupName,
                    $gitLabGroupPath
                ));
                $gitLabGroupMember = null;

                !$this->dryRun
                    ? ($gitLabGroupMember = $gitLab->groups()->addMember($gitLabGroupId, $gitLabUserId, $config["gitlab"]["options"]["newMemberAccessLevel"]))
                    : $this->logger?->warning("Operation skipped due to dry run.")
                ;

                $gitLabGroupMemberId = (
                    is_array($gitLabGroupMember)
                    && isset($gitLabGroupMember["id"])
                    && is_int($gitLabGroupMember["id"])
                )
                    ? $gitLabGroupMember["id"]
                    : sprintf("dry:%s:%d", $gitLabGroupPath, $gitLabUserId)
                ;
                $userGroupMembersSync["new"][$gitLabUserId] = $gitLabUserName;

                $this->gitLabApiCoolDown();
            }

            asort($userGroupMembersSync["new"]);
            $this->logger?->notice(sprintf(
                "%d GitLab group \"%s\" [%s] member(s) added.",
                $userGroupMembersSync["newNum"] = count($userGroupMembersSync["new"]),
                $gitLabGroupName,
                $gitLabGroupPath
            ));

            // Delete extra group members
            $this->logger?->notice("Deleting extra group members...");
            foreach ($userGroupMembersSync["found"] as $gitLabUserId => $gitLabUserName) {
                if (
                    isset($membersOfThisGroup[$gitLabUserId])
                    && $membersOfThisGroup[$gitLabUserId] === $gitLabUserName
                ) {
                    continue;
                }

                $gitLabGroupNameWithPrefix = $gitLabGroupName;
                if($config["gitlab"]["options"]["groupNamesPrefix"] !== null) {
                    $gitLabGroupNameWithPrefix = $config["gitlab"]["options"]["groupNamesPrefix"] . $gitLabGroupNameWithPrefix;
                }

                if ($this->in_array_combined($gitLabGroupNameWithPrefix, $config["gitlab"]["options"]["userNamesToIgnore"], $config["gitlab"]["options"]["userNamesToIgnoreRegex"])) {
                    $this->logger?->info(sprintf("User \"%s\" in ignore list.", $gitLabGroupNameWithPrefix));
                    continue;
                }

                $this->logger?->info(sprintf(
                    "Deleting user #%d \"%s\" from group #%d \"%s\" [%s].",
                    $gitLabUserId,
                    $gitLabUserName,
                    $gitLabGroupId,
                    $gitLabGroupName,
                    $gitLabGroupPath
                ));
                $gitLabGroupMember = null;

                /** @var GitLabGroupArray|null $gitLabUser */
                !$this->dryRun
                    ? ($gitLabGroup = $gitLab->groups()->removeMember($gitLabGroupId, $gitLabUserId))
                    : $this->logger?->warning("Operation skipped due to dry run.")
                ;

                $userGroupMembersSync["extra"][$gitLabUserId] = $gitLabUserName;

                $this->gitLabApiCoolDown();
            }

            asort($userGroupMembersSync["extra"]);
            $this->logger?->notice(sprintf(
                "%d GitLab group \"%s\" [%s] member(s) deleted.",
                $userGroupMembersSync["extraNum"] = count($userGroupMembersSync["extra"]),
                $gitLabGroupName,
                $gitLabGroupPath
            ));

            // Update existing group members
            /* This isn't needed...
            $this->logger?->notice("Updating existing group members...");
            foreach ($userGroupMembersSync["found"] as $gitLabUserId => $gitLabUserName) {
                if (
                    (
                        isset($userUserMembersSync["new"][$gitLabUserId])
                        && $userUserMembersSync["new"][$gitLabUserId] == $gitLabUserName
                    ) || (
                        isset($userUserMembersSync["extra"][$gitLabUserId])
                        && $userUserMembersSync["extra"][$gitLabUserId] == $gitLabUserName
                    )
                ) {
                    continue;
                }

                if (
                    !isset($membersOfThisGroup[$gitLabUserId])
                    || $membersOfThisGroup[$gitLabUserId] != $gitLabUserName
                ) {
                    continue;
                }

                $this->logger?->info(sprintf(
                    "Updating user #%d \"%s\" in group #%d \"%s\" [%s].",
                    $gitLabUserId,
                    $gitLabUserName,
                    $gitLabGroupId,
                    $gitLabGroupName,
                    $gitLabGroupPath
                ));
                $gitLabGroupMember = null;

                !$this->dryRun
                    ? ($gitLabGroupMember = $gitLab->groups()->saveMember($gitLabGroupId, $gitLabUserId, $config["gitlab"]["options"]["newMemberAccessLevel"]))
                    : $this->logger?->warning("Operation skipped due to dry run.")
                ;

                $userGroupMembersSync["update"][$gitLabUserId] = $gitLabUserName;
            }

            asort($userGroupMembersSync["update"]);
            $this->logger?->notice(sprintf(
                "%d GitLab group \"%s\" [%s] member(s) updated.",
                $userGroupMembersSync["updateNum"] = count($userGroupMembersSync["update"]),
                $gitLabGroupName,
                $gitLabGroupPath
            ));

            $this->gitLabApiCoolDown();
             */
        }
        // >> Handle group memberships

        // Disconnect
        $this->logger?->debug("GitLab: Unbinding");
        $gitLab = null;

        $this->logger?->notice("GitLab connection closed.");
    }

    /**
     * Case-insensitive `in_array()`.
     *
     * @param bool|int|float|string $needle
     * @param array<mixed>          $haystack
     *
     * @return bool
     */
    private function in_array_i($needle, array $haystack): bool
    {
        if ("" === ($needle = strtolower(strval($needle)))) {
            throw new \UnexpectedValueException("Needle not specified.");
        }

        return in_array($needle, array_map(function ($v) {
            return is_string($v) ? strtolower($v) : $v;
        }, $haystack), true);
    }

    /**
     * Checks if a string matches any regular expression in an array.
     *
     * @param bool|int|float|string $needle
     * @param array<mixed>          $haystack
     *
     * @return bool
     */
    private function in_array_regex($needle, array $haystack): bool
    {
        if ("" === $needle) {
            throw new \UnexpectedValueException("Needle not specified.");
        }

        foreach ($haystack as $p) {
            if(@preg_match($p, $needle) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if user is in array with either `in_array_i` or `in_array_regex`.
     * 
     * @param bool|int|float|string $needle
     * @param array<mixed>          $haystack
     * @param bool                  $useRegex
     * 
     * @return bool
     */
    private function in_array_combined($needle, array $haystack, bool $useRegex): bool
    {
        return $useRegex ? $this->in_array_regex($needle, $haystack) : $this->in_array_i($needle, $haystack);
    }

    /**
     * Case insensitive `array_key_exists()`.
     *
     * @param bool|int|float|string $key
     * @param array<mixed>          $haystack
     *
     * @return bool
     */
    private function array_key_exists_i($key, array $haystack): bool
    {
        if ("" === ($key = strtolower(strval($key)))) {
            throw new \UnexpectedValueException("Key not specified.");
        }

        foreach (array_change_key_case($haystack, CASE_LOWER) as $k => $v) {
            if ($k === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate a random password.
     *
     * @param int $length Length
     *
     * @return string Password
     */
    private function generateRandomPassword(int $length): string
    {
        if ($length < 1) {
            throw new \UnexpectedValueException("Length must be at least 1.");
        }

        $password   = "";
        $chars      = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $charsNum   = strlen($chars);
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $charsNum - 1)];
        }

        return $password;
    }

    /**
     * Get a list of built-in user names, of which should be ignored by this application.
     *
     * @return string[]
     */
    private static function getBuiltInUserNames(): array
    {
        return ["root", "ghost", "support-bot", "alert-bot"];
    }

    /**
     * Get a list of built-in group names, of which should be ignored by this application.
     *
     * @return string[]
     */
    private static function getBuiltInGroups(): array
    {
        return ["root", "lost-and-found", "Users"];
    }

    /**
     * Get a list of reserved group names, of which must be ignored by this application.
     * (The list is different for root and sub groups.)
     *
     * @see https://docs.gitlab.com/ee/user/reserved_names.html
     *
     * @param bool $isRootGroup Get the list
     *
     * @return string[]
     */
    private static function getReservedGroups(bool $isRootGroup = true): array
    {
        return $isRootGroup
            ? [
                "\\-",
                ".well-known",
                "404.html",
                "422.html",
                "500.html",
                "502.html",
                "503.html",
                "admin",
                "api",
                "apple-touch-icon.png",
                "assets",
                "dashboard",
                "deploy.html",
                "explore",
                "favicon.ico",
                "favicon.png",
                "files",
                "groups",
                "health_check",
                "help",
                "import",
                "jwt",
                "login",
                "oauth",
                "profile",
                "projects",
                "public",
                "robots.txt",
                "s",
                "search",
                "sitemap",
                "sitemap.xml",
                "sitemap.xml.gz",
                "slash-command-logo.png",
                "snippets",
                "unsubscribes",
                "uploads",
                "users",
                "v2",
            ]
            : ["\\-"]
        ;
    }

    /**
     * Wait a bit of time between each GitLab API request to avoid HTTP 500 errors when doing too many requests in a
     * short time.
     */
    private function gitLabApiCoolDown(): void
    {
        if ($this->dryRun) {
            return; // Not required for dry runs
        }

        usleep(self::API_COOL_DOWN_USECONDS);
    }
}
