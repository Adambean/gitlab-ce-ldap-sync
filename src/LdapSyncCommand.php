<?php

namespace AdamReece\GitlabCeLdapSync;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

use Cocur\Slugify\Slugify;

class LdapSyncCommand extends \Symfony\Component\Console\Command\Command
{
    /*
     * -------------------------------------------------------------------------
     * Constants
     * -------------------------------------------------------------------------
     */

    /** @const string User's configuration file name. */
    const CONFIG_FILE_NAME = "config.yml";

    /** @const string Distributed configuration file name. */
    const CONFIG_FILE_DIST_NAME = "config.yml.dist";

    /** @const int Wait this long (in microseconds) between Gitlab API calls to avoid flooding. */
    const API_COOL_DOWN_USECONDS = 100000;



    /*
     * -------------------------------------------------------------------------
     * Variables
     * -------------------------------------------------------------------------
     */

    /** @var ConsoleLogger Console logger interface */
    private $logger = null;

    /** @var bool Debug mode */
    private $dryRun = false;

    /** @var bool Continue on failure: Do not abort on certain errors */
    private $continueOnFail = false;

    /** @var string Application root directory */
    private $rootDir = null;

    /** @var string User's configuration file pathname. */
    private $configFilePathname = null;

    /** @var string Distribution configuration file pathname. */
    private $configFileDistPathname = null;



    /*
     * -------------------------------------------------------------------------
     * Command functions
     * -------------------------------------------------------------------------
     */

    /**
     * Configures the current command.
     * @return void
     */
    public function configure(): void
    {
        $this
            ->setName("ldap:sync")
            ->setDescription("Sync LDAP users and groups with a Gitlab CE/EE self-hosted installation.")
            ->addOption("dryrun", "d", InputOption::VALUE_NONE, "Dry run: Do not persist any changes.")
            ->addOption("continueOnFail", null, InputOption::VALUE_NONE, "Do not abort on certain errors. (Continue running if possible.)")
            ->addArgument("instance", InputArgument::OPTIONAL, "Sync with a specific instance, or leave unspecified to work with all.")
        ;
    }

    /**
     * Executes the current command.
     * @param  InputInterface  $input  Input interface
     * @param  OutputInterface $output Output interface
     * @return int|null                Error code, or null/zero for success
     */
    public function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $this->logger = new ConsoleLogger($output);
        $output->writeln("LDAP users and groups sync script for Gitlab-CE\n");

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
            if (!function_exists($ldapFunction)) {
                $this->logger->critical(sprintf("PHP-LDAP function \"%s\" does not exist.", $ldapFunction));
                return 1;
            }
        }



        // Load configuration
        $this->logger->notice("Loading configuration.", ["file" => $this->configFilePathname]);

        if (!($config = $this->loadConfig($this->configFilePathname))) {
            $this->logger->debug("Checking if default configuration exists but user configuration does not.", ["file" => $this->configFileDistPathname]);
            if (file_exists($this->configFileDistPathname) && !file_exists($this->configFilePathname)) {
                $this->logger->warning("Dist config found but user config not.");
                $output->writeln(sprintf("It appears that you have not created a configuration yet.\nPlease duplicate \"%s\" as \"%s\", then modify it for your\nenvironment.", self::CONFIG_FILE_DIST_NAME, self::CONFIG_FILE_NAME));
            }

            return 1;
        }

        $this->logger->notice("Loaded configuration.", ["file" => $this->configFilePathname, "config" => $config]);



        // Validate configuration
        $this->logger->notice("Validating configuration.");

        $configProblems     = [];
        $configProblemsNum  = 0;
        if (!$this->validateConfig($config, $configProblems)) {
            $this->logger->error(sprintf("%d configuration problem(s) need to be resolved.", $configProblemsNum = count($configProblems)));
            return 1;
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
            return 1;
        }

        $this->logger->notice("Retrieved directory users and groups.");



        // Check if we have anything to do...
        /* Actually, we might still want to sync users and groups aren't any of one set yet.
        if (!is_array($ldapUsers) || 1 > $ldapUsersNum) {
            $this->logger->error("Nothing to do: No users found in the directory.");
            return 1;
        }

        if (!is_array($ldapGroups) || 1 > $ldapGroupsNum) {
            $this->logger->error("Nothing to do: No groups found in the directory.");
            return 1;
        }
         */



        // Deploy to Gitlab instances
        $this->logger->notice("Deploying users and groups to Gitlab instances.");

        $gitlabInstanceOnly = trim($input->getArgument("instance"));
        foreach ($config["gitlab"]["instances"] as $gitlabInstance => $gitlabConfig) {
            if ($gitlabInstanceOnly && $gitlabInstance !== $gitlabInstanceOnly) {
                $this->logger->debug(sprintf("Skipping instance \"%s\", doesn't match the argument specified.", $gitlabInstance));
                continue;
            }

            try {
                $this->deployGitlabUsersAndGroups($config, $gitlabInstance, $gitlabConfig, $ldapUsers, $ldapUsersNum, $ldapGroups, $ldapGroupsNum);
            } catch (\Exception $e) {
                $this->logger->error(sprintf("Gitlab failure: %s", $e->getMessage()), ["error" => $e]);
                return 1;
            }
        }

        $this->logger->notice("Deployed users and groups to Gitlab instances.");



        // Finished
        return 0;
    }



    /*
     * -------------------------------------------------------------------------
     * Helper functions
     * -------------------------------------------------------------------------
     */

    /**
     * Load configuration.
     * @param  string            $file File
     * @return array<mixed>|null       Configuration, or null if failed
     */
    private function loadConfig(string $file): ?array
    {
        if (!($file = trim($file))) {
            $this->logger->critical("Configuration file not specified.");
            return null;
        }

        if (!file_exists($file)) {
            $this->logger->critical("Configuration file not found.");
            return null;
        }

        if (!is_file($file)) {
            $this->logger->critical("Configuration file not a file.");
            return null;
        }

        if (!is_readable($file)) {
            $this->logger->critical("Configuration file not readable.");
            return null;
        }

        $yaml = null;

        try {
            $yaml = Yaml::parseFile($file);
        } catch (ParseException $e) {
            $this->logger->critical(sprintf("Configuration file could not be parsed: %s", $e->getMessage()));
            return null;
        } catch (\Exception $e) {
            $this->logger->critical(sprintf("Configuration file could not be loaded: %s", $e->getMessage()));
            return null;
        }

        if (!is_array($yaml)) {
            $this->logger->critical("Configuration format invalid.");
            return null;
        }

        if (empty($yaml)) {
            $this->logger->critical("Configuration empty.");
            return null;
        }

        return $yaml;
    }

    /**
     * Validate configuration.
     * @param  array<mixed>             $config   Configuration (this will be modified for type strictness and trimming)
     * @param  array<string,array>|null $problems Optional output of problems indexed by type
     * @return bool                               True if valid, false if invalid
     */
    private function validateConfig(array &$config, array &$problems = null): bool
    {
        if (!is_array($problems)) {
            $problems = [];
        }

        $problems = [
            "warning"   => [],
            "error"     => [],
        ];

        /**
         * Add a problem.
         * @param  string $type    Problem type (error or warning)
         * @param  string $message Problem description
         * @return void
         */
        $addProblem = function(string $type, string $message) use (&$problems): void {

            if (!($type = trim($type))) {
                return;
            }

            if (!isset($problems[$type]) || !is_array($problems[$type])) {
                throw new \UnexpectedValueException("Type invalid.");
            }

            if (!($message = trim($message))) {
                return;
            }

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
                } elseif (!($config["ldap"]["server"]["host"] = trim($config["ldap"]["server"]["host"]))) {
                    $addProblem("error", "ldap->server->host not specified.");
                }

                if (!isset($config["ldap"]["server"]["port"])) {
                    $addProblem("warning", "ldap->server->port missing. (It will be determined by the encryption setting.)");
                    $config["ldap"]["server"]["port"] = null;
                } elseif (!($config["ldap"]["server"]["port"] = intval($config["ldap"]["server"]["port"]))) {
                    $addProblem("warning", "ldap->server->port not specified. (It will be determined by the encryption setting.)");
                    $config["ldap"]["server"]["port"] = null;
                } elseif ($config["ldap"]["server"]["port"] < 1 || $config["ldap"]["server"]["port"] > 65535) {
                    $addProblem("error", "ldap->server->port out of range. (Must be 1-65535.)");
                }

                if (!isset($config["ldap"]["server"]["version"])) {
                    $addProblem("warning", "ldap->server->version missing. (Assuming 3.)");
                    $config["ldap"]["server"]["version"] = 3;
                } elseif (!($config["ldap"]["server"]["version"] = intval($config["ldap"]["server"]["version"]))) {
                    $addProblem("warning", "ldap->server->version not specified. (Assuming 3.)");
                    $config["ldap"]["server"]["version"] = 3;
                } elseif ($config["ldap"]["server"]["version"] < 1 || $config["ldap"]["server"]["version"] > 3) {
                    $addProblem("error", "ldap->server->version out of range. (Must be 1-3.)");
                }

                if (!isset($config["ldap"]["server"]["encryption"])) {
                    $addProblem("warning", "ldap->server->encryption missing. (Assuming none.)");
                    $config["ldap"]["server"]["encryption"] = "none";
                } elseif (!($config["ldap"]["server"]["encryption"] = trim($config["ldap"]["server"]["encryption"]))) {
                    $addProblem("warning", "ldap->server->encryption not specified. (Assuming none.)");
                    $config["ldap"]["server"]["encryption"] = "none";
                } else {
                    switch ($config["ldap"]["server"]["encryption"]) {
                        case "none":
                        case "tls":
                            if (!$config["ldap"]["server"]["port"]) {
                                $config["ldap"]["server"]["port"] = 389;
                            }
                            break;

                        case "ssl":
                            if (!$config["ldap"]["server"]["port"]) {
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
                } elseif (!($config["ldap"]["server"]["bindDn"] = trim($config["ldap"]["server"]["bindDn"]))) {
                    $addProblem("warning", "ldap->server->bindDn not specified. (Assuming anonymous access.)");
                    $config["ldap"]["server"]["bindDn"] = null;
                } else {
                    if (!isset($config["ldap"]["server"]["bindPassword"])) {
                        $addProblem("warning", "ldap->server->bindPassword missing. (Must be specified for non-anonymous access.)");
                    } elseif (!strlen($config["ldap"]["server"]["bindPassword"])) {
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
                } elseif (!($config["ldap"]["queries"]["baseDn"] = trim($config["ldap"]["queries"]["baseDn"]))) {
                    $addProblem("error", "ldap->queries->baseDn not specified.");
                }

                if (!isset($config["ldap"]["queries"]["userDn"])) {
                    $addProblem("error", "ldap->queries->userDn missing.");
                } elseif (!($config["ldap"]["queries"]["userDn"] = trim($config["ldap"]["queries"]["userDn"]))) {
                    // $addProblem("warning", "ldap->queries->userDn not specified.");
                    // This is OK: Users will be looked for from the directory root.
                }

                if (
                    !empty($config["ldap"]["queries"]["baseDn"]) &&
                    !empty($config["ldap"]["queries"]["userDn"]) &&
                    strripos($config["ldap"]["queries"]["userDn"], $config["ldap"]["queries"]["baseDn"]) === (strlen($config["ldap"]["queries"]["userDn"]) - strlen($config["ldap"]["queries"]["baseDn"]))
                ) {
                    $addProblem("warning", "ldap->queries->userDn wrongly ends with ldap->queries->baseDn, this could cause user objects to not be found.");
                }

                if (!isset($config["ldap"]["queries"]["userFilter"])) {
                    $addProblem("error", "ldap->queries->userFilter missing.");
                } elseif (!($config["ldap"]["queries"]["userFilter"] = trim($config["ldap"]["queries"]["userFilter"]))) {
                    $addProblem("error", "ldap->queries->userFilter not specified.");
                }

                if (!isset($config["ldap"]["queries"]["userUniqueAttribute"])) {
                    $addProblem("error", "ldap->queries->userUniqueAttribute missing.");
                } elseif (!($config["ldap"]["queries"]["userUniqueAttribute"] = trim($config["ldap"]["queries"]["userUniqueAttribute"]))) {
                    $addProblem("error", "ldap->queries->userUniqueAttribute not specified.");
                }

                if (!isset($config["ldap"]["queries"]["userMatchAttribute"])) {
                    $addProblem("warning", "ldap->queries->userMatchAttribute missing. (Assuming == userUniqueAttribute.)");
                    $config["ldap"]["queries"]["userMatchAttribute"] = $config["ldap"]["queries"]["userUniqueAttribute"];
                } elseif (!($config["ldap"]["queries"]["userMatchAttribute"] = trim($config["ldap"]["queries"]["userMatchAttribute"]))) {
                    $addProblem("warning", "ldap->queries->userMatchAttribute not specified. (Assuming == userUniqueAttribute.)");
                    $config["ldap"]["queries"]["userMatchAttribute"] = $config["ldap"]["queries"]["userUniqueAttribute"];
                }

                if (!isset($config["ldap"]["queries"]["userNameAttribute"])) {
                    $addProblem("error", "ldap->queries->userNameAttribute missing.");
                } elseif (!($config["ldap"]["queries"]["userNameAttribute"] = trim($config["ldap"]["queries"]["userNameAttribute"]))) {
                    $addProblem("error", "ldap->queries->userNameAttribute not specified.");
                }

                if (!isset($config["ldap"]["queries"]["userEmailAttribute"])) {
                    $addProblem("error", "ldap->queries->userEmailAttribute missing.");
                } elseif (!($config["ldap"]["queries"]["userEmailAttribute"] = trim($config["ldap"]["queries"]["userEmailAttribute"]))) {
                    $addProblem("error", "ldap->queries->userEmailAttribute not specified.");
                }

                if (!isset($config["ldap"]["queries"]["userSshKeyAttribute"])) {
                    $addProblem("warning", "ldap->queries->userSshKeyAttribute missing.");
                } elseif (!$config["ldap"]["queries"]["userSshKeyAttribute"] = trim($config["ldap"]["queries"]["userSshKeyAttribute"])) {
                    $addProblem("warning", "ldap->queries->userSshKeyAttribute not specified.");
                }

                if (!isset($config["ldap"]["queries"]["groupDn"])) {
                    $addProblem("error", "ldap->queries->groupDn missing.");
                } elseif (!($config["ldap"]["queries"]["groupDn"] = trim($config["ldap"]["queries"]["groupDn"]))) {
                    // $addProblem("error", "ldap->queries->groupDn not specified.");
                    // This is OK: Groups will be looked for from the directory root.
                }

                if (
                    !empty($config["ldap"]["queries"]["baseDn"]) &&
                    !empty($config["ldap"]["queries"]["groupDn"]) &&
                    strripos($config["ldap"]["queries"]["groupDn"], $config["ldap"]["queries"]["baseDn"]) === (strlen($config["ldap"]["queries"]["groupDn"]) - strlen($config["ldap"]["queries"]["baseDn"]))
                ) {
                    $addProblem("warning", "ldap->queries->groupDn wrongly ends with ldap->queries->baseDn, this could cause user objects to not be found.");
                }

                if (!isset($config["ldap"]["queries"]["groupFilter"])) {
                    $addProblem("error", "ldap->queries->groupFilter missing.");
                } elseif (!($config["ldap"]["queries"]["groupFilter"] = trim($config["ldap"]["queries"]["groupFilter"]))) {
                    $addProblem("error", "ldap->queries->groupFilter not specified.");
                }

                if (!isset($config["ldap"]["queries"]["groupUniqueAttribute"])) {
                    $addProblem("error", "ldap->queries->groupUniqueAttribute missing.");
                } elseif (!($config["ldap"]["queries"]["groupUniqueAttribute"] = trim($config["ldap"]["queries"]["groupUniqueAttribute"]))) {
                    $addProblem("error", "ldap->queries->groupUniqueAttribute not specified.");
                }

                if (!isset($config["ldap"]["queries"]["groupMemberAttribute"])) {
                    $addProblem("error", "ldap->queries->groupMemberAttribute missing.");
                } elseif (!($config["ldap"]["queries"]["groupMemberAttribute"] = trim($config["ldap"]["queries"]["groupMemberAttribute"]))) {
                    $addProblem("error", "ldap->queries->groupMemberAttribute not specified.");
                }
            }
            // >> LDAP queries
        }
        // >> LDAP

        // << Gitlab
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

            // << Gitlab options
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
                } elseif (!empty($config["gitlab"]["options"]["userNamesToIgnore"])) {
                    foreach ($config["gitlab"]["options"]["userNamesToIgnore"] as $i => $userName) {
                        if (!is_string($userName)) {
                            $addProblem("error", sprintf("gitlab->options->userNamesToIgnore[%d] is not a string.", $i));
                            continue;
                        }

                        if (!($config["gitlab"]["options"]["userNamesToIgnore"][$i] = trim($userName))) {
                            $addProblem("error", sprintf("gitlab->options->userNamesToIgnore[%d] not specified.", $i));
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
                } elseif (!empty($config["gitlab"]["options"]["groupNamesToIgnore"])) {
                    foreach ($config["gitlab"]["options"]["groupNamesToIgnore"] as $i => $groupName) {
                        if (!is_string($groupName)) {
                            $addProblem("error", sprintf("gitlab->options->groupNamesToIgnore[%d] is not a string.", $i));
                            continue;
                        }

                        if (!($config["gitlab"]["options"]["groupNamesToIgnore"][$i] = trim($groupName))) {
                            $addProblem("error", sprintf("gitlab->options->groupNamesToIgnore[%d] not specified.", $i));
                            continue;
                        }
                    }
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

                if (!isset($config["gitlab"]["options"]["sshKeysImportMode"])) {
                    $addProblem("warning", "gitlab->options->sshKeysImportMode missing. (Assuming false.)");
                    $config["gitlab"]["options"]["sshKeysImportMode"] = false;
                } elseif (null === $config["gitlab"]["options"]["sshKeysImportMode"]) {
                    $addProblem("warning", "gitlab->options->sshKeysImportMode not specified. (Assuming false.)");
                    $config["gitlab"]["options"]["sshKeysImportMode"] = false;
                } elseif ($config["gitlab"]["options"]["sshKeysImportMode"]) {
                    switch ($config["gitlab"]["options"]["sshKeysImportMode"]) {
                        case "insert":
                        case "merge":
                        case "replace":
                            break;

                        default:
                            $addProblem("error", "gitlab->options->sshKeysImportMode invalid. (Must be \"insert\", \"merge\", or \"replace\".)");
                    }
                }

                if (!isset($config["gitlab"]["options"]["groupNamesOfAdministrators"])) {
                    // $addProblem("warning", "gitlab->options->groupNamesOfAdministrators missing. (Assuming none.)");
                    $config["gitlab"]["options"]["groupNamesOfAdministrators"] = [];
                } elseif ("" === $config["gitlab"]["options"]["groupNamesOfAdministrators"]) {
                    $addProblem("warning", "gitlab->options->groupNamesOfAdministrators not specified. (Assuming none.)");
                    $config["gitlab"]["options"]["groupNamesOfAdministrators"] = [];
                } elseif (!is_array($config["gitlab"]["options"]["groupNamesOfAdministrators"])) {
                    $addProblem("error", "gitlab->options->groupNamesOfAdministrators is not an array.");
                } elseif (!empty($config["gitlab"]["options"]["groupNamesOfAdministrators"])) {
                    foreach ($config["gitlab"]["options"]["groupNamesOfAdministrators"] as $i => $groupName) {
                        if (!is_string($groupName)) {
                            $addProblem("error", sprintf("gitlab->options->groupNamesOfAdministrators[%d] is not a string.", $i));
                            continue;
                        }

                        if (!($config["gitlab"]["options"]["groupNamesOfAdministrators"][$i] = trim($groupName))) {
                            $addProblem("error", sprintf("gitlab->options->groupNamesOfAdministrators[%d] not specified.", $i));
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
                } elseif (!empty($config["gitlab"]["options"]["groupNamesOfExternal"])) {
                    foreach ($config["gitlab"]["options"]["groupNamesOfExternal"] as $i => $groupName) {
                        if (!is_string($groupName)) {
                            $addProblem("error", sprintf("gitlab->options->groupNamesOfExternal[%d] is not a string.", $i));
                            continue;
                        }

                        if (!($config["gitlab"]["options"]["groupNamesOfExternal"][$i] = trim($groupName))) {
                            $addProblem("error", sprintf("gitlab->options->groupNamesOfExternal[%d] not specified.", $i));
                            continue;
                        }
                    }
                }
            }
            // >> Gitlab options

            // << Gitlab instances
            if (!isset($config["gitlab"]["instances"]) || !is_array($config["gitlab"]["instances"])) {
                $addProblem("error", "gitlab->instances missing.");
            } else {
                foreach (array_keys($config["gitlab"]["instances"]) as $instance) {
                    if (!isset($config["gitlab"]["instances"][$instance]["url"])) {
                        $addProblem("error", sprintf("gitlab->instances->%s->url missing.", $instance));
                    } elseif (!($config["gitlab"]["instances"][$instance]["url"] = trim($config["gitlab"]["instances"][$instance]["url"]))) {
                        $addProblem("error", sprintf("gitlab->instances->%s->url not specified.", $instance));
                    }

                    if (!isset($config["gitlab"]["instances"][$instance]["token"])) {
                        $addProblem("error", sprintf("gitlab->instances->%s->token missing.", $instance));
                    } elseif (!($config["gitlab"]["instances"][$instance]["token"] = trim($config["gitlab"]["instances"][$instance]["token"]))) {
                        $addProblem("error", sprintf("gitlab->instances->%s->token not specified.", $instance));
                    }
                }
            }
            // >> Gitlab instances
        }
        // >> Gitlab

        return (is_array($problems) && isset($problems["error"]) && is_array($problems["error"]) && empty($problems["error"]));
    }

    /**
     * Get users and groups from LDAP.
     * @param  array<mixed>        $config    Validated configuration
     * @param  array<string,array> $users     Users output
     * @param  int                 $usersNum  Users count output
     * @param  array<string,array> $groups    Groups output
     * @param  int                 $groupsNum Groups count output
     * @return void                           Success if returned, exception thrown on error
     */
    private function getLdapUsersAndGroups(array $config, array &$users, int &$usersNum, array &$groups, int &$groupsNum): void
    {
        $slugifyLdapUsername = new Slugify([
            "regexp"        => "/([^A-Za-z0-9-_\.])+/",
            "separator"     => ",",
            "lowercase"     => false,
            "trim"          => true,
        ]);

        // Connect
        $this->logger->notice("Establishing LDAP connection.", [
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
            $this->logger->debug("LDAP: Enabling debug mode");
            @ldap_set_option(null, LDAP_OPT_DEBUG_LEVEL, 6);
        }

        // Solves: ldap_search(): Search: Operations error.
        // Occurs when no "user_dn" has been specified.
        // https://stackoverflow.com/questions/17742751/ldap-operations-error
        if ($config["ldap"]["winCompatibilityMode"]) {
            $this->logger->debug("LDAP: Enabling compatibility mode");
            @ldap_set_option(null, LDAP_OPT_REFERRALS, 0);
        }

        $this->logger->debug("LDAP: Connecting", ["uri" => $ldapUri]);
        if (false === ($ldap = @ldap_connect($ldapUri))) {
            throw new \RuntimeException(sprintf("LDAP connection will not be possible. Check that your server address and port \"%s\" are plausible.", $ldapUri));
        }

        $this->logger->debug("LDAP: Setting options");
        if (false === @ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, $config["ldap"]["server"]["version"])) {
            throw new \RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
        }

        if ("tls" === $config["ldap"]["server"]["encryption"]) {
            $this->logger->debug("LDAP: STARTTLS");
            if (false === @ldap_start_tls($ldap)) {
                throw new \RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
            }
        }

        $this->logger->debug("LDAP: Binding", ["dn" => $config["ldap"]["server"]["bindDn"]]);
        if (false === @ldap_bind($ldap, $config["ldap"]["server"]["bindDn"], $config["ldap"]["server"]["bindPassword"])) {
            throw new \RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
        }

        $this->logger->notice("LDAP connection established.");

        // << Retrieve users
        $ldapUsersQueryBase = sprintf(
            "%s%s%s",
            $config["ldap"]["queries"]["userDn"],
            strlen($config["ldap"]["queries"]["userDn"]) >= 1 ? "," : "",
            $config["ldap"]["queries"]["baseDn"]
        );

        $ldapUsersQueryAttributes = [
            $config["ldap"]["queries"]["userUniqueAttribute"],
            $config["ldap"]["queries"]["userMatchAttribute"],
            $config["ldap"]["queries"]["userNameAttribute"],
            $config["ldap"]["queries"]["userEmailAttribute"],
        ];

        $this->logger->debug("Retrieving users.", [
            "base"          => $ldapUsersQueryBase,
            "filter"        => $config["ldap"]["queries"]["userFilter"],
            "attributes"    => $ldapUsersQueryAttributes,
        ]);

        if (false === ($ldapUsersQuery = @ldap_search($ldap, $ldapUsersQueryBase, $config["ldap"]["queries"]["userFilter"], $ldapUsersQueryAttributes))) {
            throw new \RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
        }

        $ldapUserAttribute      = strtolower($config["ldap"]["queries"]["userUniqueAttribute"]);
        $ldapUserMatchAttribute = strtolower($config["ldap"]["queries"]["userMatchAttribute"]);
        $ldapNameAttribute      = strtolower($config["ldap"]["queries"]["userNameAttribute"]);
        $ldapEmailAttribute     = strtolower($config["ldap"]["queries"]["userEmailAttribute"]);

        if (is_array($ldapUsers = @ldap_get_entries($ldap, $ldapUsersQuery)) && is_iterable($ldapUsers)) {
            if (($ldapUsersNum = count($ldapUsers)) >= 1) {
                $this->logger->notice(sprintf("%d directory user(s) found.", $ldapUsersNum));
                $ldapSshKeyAttribute    = strtolower($config["ldap"]["queries"]["userSshKeyAttribute"]);

                foreach ($ldapUsers as $i => $ldapUser) {
                    if (!is_int($i)) {
                        continue;
                    }
                    $n = $i + 1;

                    if (!is_array($ldapUser)) {
                        $this->logger->error(sprintf("User #%d: Not an array.", $n));
                        continue;
                    }

                    if (!isset($ldapUser["dn"]) || !is_string($ldapUser["dn"])) {
                        $this->logger->error(sprintf("User #%d: Missing distinguished name.", $n));
                        continue;
                    }

                    if (!($ldapUserDn = trim($ldapUser["dn"]))) {
                        $this->logger->error(sprintf("User #%d: Empty distinguished name.", $n));
                        continue;
                    }

                    if (!isset($ldapUser[$ldapUserAttribute])) {
                        $this->logger->error(sprintf("User #%d [%s]: Missing attribute \"%s\".", $n, $ldapUserDn, $ldapUserAttribute));
                        continue;
                    }

                    if (!is_array($ldapUser[$ldapUserAttribute]) || !isset($ldapUser[$ldapUserAttribute][0]) || !is_string($ldapUser[$ldapUserAttribute][0])) {
                        $this->logger->error(sprintf("User #%d [%s]: Invalid attribute \"%s\".", $n, $ldapUserDn, $ldapUserAttribute));
                        continue;
                    }

                    if (!($ldapUserName = trim($ldapUser[$ldapUserAttribute][0]))) {
                        $this->logger->error(sprintf("User #%d [%s]: Empty attribute \"%s\".", $n, $ldapUserDn, $ldapUserAttribute));
                        continue;
                    }

                    // Make sure the username format is compatible with Gitlab later on
                    if (($ldapUserNameSlugified = $slugifyLdapUsername->slugify($ldapUserName)) !== $ldapUserName) {
                        $this->logger->warning(sprintf("User #%d [%s]: Username \"%s\" is incompatible with Gitlab, changed to \"%s\".", $n, $ldapUserDn, $ldapUserName, $ldapUserNameSlugified));
                        $ldapUserName = $ldapUserNameSlugified;
                    }

                    if (!isset($ldapUser[$ldapUserMatchAttribute])) {
                        $this->logger->error(sprintf("User #%d [%s]: Missing attribute \"%s\".", $n, $ldapUserDn, $ldapUserMatchAttribute));
                        continue;
                    }

                    if (!is_array($ldapUser[$ldapUserMatchAttribute]) || !isset($ldapUser[$ldapUserMatchAttribute][0]) || !is_string($ldapUser[$ldapUserMatchAttribute][0])) {
                        $this->logger->error(sprintf("User #%d [%s]: Invalid attribute \"%s\".", $n, $ldapUserDn, $ldapUserMatchAttribute));
                        continue;
                    }

                    if (!($ldapUserMatch = trim($ldapUser[$ldapUserMatchAttribute][0]))) {
                        $this->logger->error(sprintf("User #%d [%s]: Empty attribute \"%s\".", $n, $ldapUserDn, $ldapUserMatchAttribute));
                        continue;
                    }

                    if (!isset($ldapUser[$ldapNameAttribute])) {
                        $this->logger->error(sprintf("User #%d [%s]: Missing attribute \"%s\".", $n, $ldapUserDn, $ldapNameAttribute));
                        continue;
                    }

                    if (!is_array($ldapUser[$ldapNameAttribute]) || !isset($ldapUser[$ldapNameAttribute][0]) || !is_string($ldapUser[$ldapNameAttribute][0])) {
                        $this->logger->error(sprintf("User #%d [%s]: Invalid attribute \"%s\".", $n, $ldapUserDn, $ldapNameAttribute));
                        continue;
                    }

                    if (!($ldapUserFullName = trim($ldapUser[$ldapNameAttribute][0]))) {
                        $this->logger->error(sprintf("User #%d [%s]: Empty attribute \"%s\".", $n, $ldapUserDn, $ldapNameAttribute));
                        continue;
                    }

                    if (!isset($ldapUser[$ldapEmailAttribute])) {
                        $this->logger->error(sprintf("User #%d [%s]: Missing attribute \"%s\".", $n, $ldapUserDn, $ldapEmailAttribute));
                        continue;
                    }

                    if (!is_array($ldapUser[$ldapEmailAttribute]) || !isset($ldapUser[$ldapEmailAttribute][0]) || !is_string($ldapUser[$ldapEmailAttribute][0])) {
                        $this->logger->error(sprintf("User #%d [%s]: Invalid attribute \"%s\".", $n, $ldapUserDn, $ldapEmailAttribute));
                        continue;
                    }

                    if (!($ldapUserEmail = trim($ldapUser[$ldapEmailAttribute][0]))) {
                        $this->logger->error(sprintf("User #%d [%s]: Empty attribute \"%s\".", $n, $ldapUserDn, $ldapEmailAttribute));
                        continue;
                    }

                    $ldapUserSshKeys = null;
                    if ($ldapSshKeyAttribute) {
                        $ldapUserSshKeys = [];

                        if (!isset($ldapUser[$ldapSshKeyAttribute])) {
                            $this->logger->warning(sprintf("User #%d [%s]: Missing attribute \"%s\".", $n, $ldapUserDn, $ldapSshKeyAttribute));
                        } elseif (!is_array($ldapUser[$ldapSshKeyAttribute])) {
                            $this->logger->warning(sprintf("User #%d [%s]: Invalid attribute \"%s\".", $n, $ldapUserDn, $ldapSshKeyAttribute));
                        } elseif (count($ldapUserSshKeys = $ldapUser[$ldapSshKeyAttribute]) < 1) {
                            $this->logger->warning(sprintf("User #%d [%s]: Empty attribute \"%s\".", $n, $ldapUserDn, $ldapSshKeyAttribute));
                        }
                    }

                    if ($this->in_array_i($ldapUserName, $config["gitlab"]["options"]["userNamesToIgnore"])) {
                        $this->logger->info(sprintf("User \"%s\" in ignore list.", $ldapUserName));
                        continue;
                    }

                    $this->logger->info(sprintf("Found directory user \"%s\" [%s].", $ldapUserName, $ldapUserDn));
                    if (isset($users[$ldapUserName]) && is_array($users[$ldapUserName])) {
                        $this->logger->warning(sprintf("Duplicate directory user \"%s\" [%s].", $ldapUserName, $ldapUserDn));
                        continue;
                    }

                    $users[$ldapUserName] = [
                        "dn"            => $ldapUserDn,
                        "username"      => $ldapUserName,
                        "userMatchId"   => $ldapUserMatch,
                        "fullName"      => $ldapUserFullName,
                        "email"         => $ldapUserEmail,
                        "sshKeys"       => $ldapUserSshKeys,
                        "isAdmin"       => false,
                        "isExternal"    => false,
                    ];
                }

                ksort($users);
                $this->logger->notice(sprintf("%d directory user(s) recognised.", $usersNum = count($users)));
            } else {
                $this->logger->warning("No directory users found.");
            }
        } else {
            $this->logger->error("Directory users query failed.");
        }
        // >> Retrieve users

        // << Retrieve groups
        $ldapGroupsQueryBase = sprintf(
            "%s%s%s",
            $config["ldap"]["queries"]["groupDn"],
            strlen($config["ldap"]["queries"]["groupDn"]) >= 1 ? "," : "",
            $config["ldap"]["queries"]["baseDn"]
        );

        $ldapGroupsQueryAttributes = [
            $config["ldap"]["queries"]["groupUniqueAttribute"],
            $config["ldap"]["queries"]["groupMemberAttribute"],
        ];

        $this->logger->debug("Retrieving groups.", [
            "base"          => $ldapGroupsQueryBase,
            "filter"        => $config["ldap"]["queries"]["groupFilter"],
            "attributes"    => $ldapGroupsQueryAttributes,
        ]);

        if (false === ($ldapGroupsQuery = @ldap_search($ldap, $ldapGroupsQueryBase, $config["ldap"]["queries"]["groupFilter"], $ldapGroupsQueryAttributes))) {
            throw new \RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
        }

        $ldapGroupAttribute         = strtolower($config["ldap"]["queries"]["groupUniqueAttribute"]);
        $ldapGroupMemberAttribute   = strtolower($config["ldap"]["queries"]["groupMemberAttribute"]);

        if (is_array($ldapGroups = @ldap_get_entries($ldap, $ldapGroupsQuery)) && is_iterable($ldapGroups)) {
            if (($ldapGroupsNum = count($ldapGroups)) >= 1) {
                $this->logger->notice(sprintf("%d directory group(s) found.", $ldapGroupsNum));

                foreach ($ldapGroups as $i => $ldapGroup) {
                    if (!is_int($i)) {
                        continue;
                    }
                    $n = $i + 1;

                    if (!is_array($ldapGroup)) {
                        $this->logger->error(sprintf("Group #%d: Not an array.", $n));
                        continue;
                    }

                    if (!isset($ldapGroup[$ldapGroupAttribute])) {
                        $this->logger->error(sprintf("Group #%d: Missing attribute \"%s\".", $n, $ldapGroupAttribute));
                        continue;
                    }

                    if (!is_array($ldapGroup[$ldapGroupAttribute]) || !isset($ldapGroup[$ldapGroupAttribute][0])) {
                        $this->logger->error(sprintf("Group #%d: Invalid attribute \"%s\".", $n, $ldapGroupAttribute));
                        continue;
                    }

                    if (!($ldapGroupName = trim($ldapGroup[$ldapGroupAttribute][0]))) {
                        $this->logger->error(sprintf("Group #%d: Empty attribute \"%s\".", $n, $ldapGroupAttribute));
                        continue;
                    }

                    if ($this->in_array_i($ldapGroupName, $config["gitlab"]["options"]["groupNamesToIgnore"])) {
                        $this->logger->info(sprintf("Group \"%s\" in ignore list.", $ldapGroupName));
                        continue;
                    }

                    $this->logger->info(sprintf("Found directory group \"%s\".", $ldapGroupName));
                    if (isset($groups[$ldapGroupName])) {
                        $this->logger->warning(sprintf("Duplicate directory group \"%s\".", $ldapGroupName));
                        continue;
                    }

                    $groups[$ldapGroupName] = [];

                    if (!isset($ldapGroup[$ldapGroupMemberAttribute])) {
                        $this->logger->warning(sprintf("Group #%d: Missing attribute \"%s\". (Could also mean this group has no members.)", $n, $ldapGroupMemberAttribute));
                        continue;
                    }

                    if (!is_array($ldapGroup[$ldapGroupMemberAttribute])) {
                        $this->logger->warning(sprintf("Group #%d: Invalid attribute \"%s\".", $n, $ldapGroupMemberAttribute));
                        continue;
                    }

                    if ($groupMembersAreAdmin = $this->in_array_i($ldapGroupName, $config["gitlab"]["options"]["groupNamesOfAdministrators"])) {
                        $this->logger->info(sprintf("Group \"%s\" members are administrators.", $ldapGroupName));
                    }

                    if ($groupMembersAreExternal = $this->in_array_i($ldapGroupName, $config["gitlab"]["options"]["groupNamesOfExternal"])) {
                        $this->logger->info(sprintf("Group \"%s\" members are external.", $ldapGroupName));
                    }

                    // Retrieve group user memberships
                    foreach ($ldapGroup[$ldapGroupMemberAttribute] as $j => $ldapGroupMember) {
                        if (!is_int($j)) {
                            continue;
                        }
                        $o = $j + 1;

                        if (!is_string($ldapGroupMember)) {
                            $this->logger->warning(sprintf("Group #%d / member #%d: Invalid member attribute \"%s\".", $n, $o, $ldapGroupMemberAttribute));
                            continue;
                        }

                        if (!($ldapGroupMemberName = trim($ldapGroupMember))) {
                            $this->logger->warning(sprintf("Group #%d / member #%d: Empty member attribute \"%s\".", $n, $o, $ldapGroupMemberAttribute));
                            continue;
                        }

                        $ldapUserMatchFound = false;
                        if ($this->in_array_i($ldapGroupMemberAttribute, ["memberUid"])) {
                            foreach ($users as $userName => $user) {
                                if (($ldapUserMatchAttribute === $ldapUserAttribute ? $userName : $user["userMatchId"]) == $ldapGroupMemberName) {
                                    $ldapGroupMemberName = $userName;
                                    $this->logger->debug(sprintf("Group #%d / member #%d: User ID \"%s\" matched to user name \"%s\".", $n, $o, $user["userMatchId"], $userName));
                                    $ldapUserMatchFound = true;
                                    break;
                                }
                            }
                        } elseif ($this->in_array_i($ldapGroupMemberAttribute, ["member", "uniqueMember"])) {
                            foreach ($users as $userName => $user) {
                                if ($user["dn"] == $ldapGroupMemberName) {
                                    $ldapGroupMemberName = $userName;
                                    $this->logger->debug(sprintf("Group #%d / member #%d: User ID \"%s\" matched to user name \"%s\".", $n, $o, $user["dn"], $userName));
                                    $ldapUserMatchFound = true;
                                    break;
                                }
                            }
                        }

                        if (!$ldapUserMatchFound) {
                            $this->logger->warning(sprintf("Group #%d / member #%d: No matching user name found for group member attribute \"%s\".", $n, $o, $ldapGroupMemberAttribute));
                            continue;
                        }

                        if ($this->in_array_i($ldapGroupMemberName, $config["gitlab"]["options"]["userNamesToIgnore"])) {
                            $this->logger->info(sprintf("Group #%d / member #%d: User \"%s\" in ignore list.", $n, $o, $ldapGroupMemberName));
                            continue;
                        }

                        if (!isset($users[$ldapGroupMemberName]) || !is_array($users[$ldapGroupMemberName])) {
                            $this->logger->warning(sprintf("Group #%d / member #%d: User not found \"%s\".", $n, $o, $ldapGroupMemberName));
                            continue;
                        }

                        $this->logger->info(sprintf("Found directory group \"%s\" member \"%s\".", $ldapGroupName, $ldapGroupMemberName));
                        if (isset($groups[$ldapGroupName][$ldapGroupMemberName])) {
                            $this->logger->warning(sprintf("Duplicate directory group \"%s\" member \"%s\".", $ldapGroupName, $ldapGroupMemberName));
                            continue;
                        }

                        $groups[$ldapGroupName][] = $ldapGroupMemberName;

                        if ($groupMembersAreAdmin) {
                            $this->logger->info(sprintf("Group #%d / member #%d: User \"%s\" is an administrator.", $n, $o, $ldapGroupMemberName));
                            $users[$ldapGroupMemberName]["isAdmin"] = true;
                        }

                        if ($groupMembersAreExternal) {
                            $this->logger->info(sprintf("Group #%d / member #%d: User \"%s\" is external.", $n, $o, $ldapGroupMemberName));
                            $users[$ldapGroupMemberName]["isExternal"] = true;
                        }
                    }

                    $this->logger->notice(sprintf("%d directory group \"%s\" member(s) recognised.", $groupUsersNum = count($groups[$ldapGroupName]), $ldapGroupName));
                    sort($groups[$ldapGroupName]);
                }

                ksort($groups);
                $this->logger->notice(sprintf("%d directory group(s) recognised.", $groupsNum = count($groups)));
            } else {
                $this->logger->warning("No directory groups found.");
            }
        } else {
            $this->logger->error("Directory groups query failed.");
        }
        // >> Retrieve groups

        // Disconnect
        $this->logger->debug("LDAP: Unbinding");
        if (false === @ldap_unbind($ldap)) {
            throw new \RuntimeException(sprintf("%s. (Code %d)", @ldap_error($ldap), @ldap_errno($ldap)));
        }
        $ldap = null;

        $this->logger->notice("LDAP connection closed.");
    }

    /**
     * Deploy users and groups to a Gitlab instance.
     * @param  array<mixed>        $config         Validated configuration
     * @param  string              $gitlabInstance Gitlab instance name
     * @param  array<mixed>        $gitlabConfig   Gitlab instance configuration
     * @param  array<string,array> $ldapUsers      LDAP users
     * @param  int                 $ldapUsersNum   LDAP users count
     * @param  array<string,array> $ldapGroups     LDAP groups
     * @param  int                 $ldapGroupsNum  LDAP groups count
     * @return void                                Success if returned, exception thrown on error
     */
    private function deployGitlabUsersAndGroups(array $config, string $gitlabInstance, array $gitlabConfig, array $ldapUsers, int $ldapUsersNum, array $ldapGroups, int $ldapGroupsNum): void
    {
        $slugifyGitlabName = new Slugify([
            "regexp"        => "/([^A-Za-z0-9]|-_\. )+/",
            "separator"     => " ",
            "lowercase"     => false,
            "trim"          => true,
        ]);

        $slugifyGitlabPath = new Slugify([
            "regexp"        => "/([^A-Za-z0-9]|-_\.)+/",
            "separator"     => "-",
            "lowercase"     => true,
            "trim"          => true,
        ]);

        // Convert LDAP group names into a format safe for Gitlab's restrictions
        $ldapGroupsSafe = [];
        foreach ($ldapGroups as $ldapGroupName => $ldapGroupMembers) {
            $ldapGroupsSafe[$slugifyGitlabName->slugify($ldapGroupName)] = $ldapGroupMembers;
        }

        // Connect
        $this->logger->notice("Establishing Gitlab connection.", [
            "instance"  => $gitlabInstance,
            "url"       => $gitlabConfig["url"],
        ]);

        $this->logger->debug("Gitlab: Connecting");
        $gitlab = \Gitlab\Client::create($gitlabConfig["url"])
            ->authenticate($gitlabConfig["token"], \Gitlab\Client::AUTH_HTTP_TOKEN)
        ;

        // << Handle users
        $usersSync = [
            "found"     => [],  // All existing Gitlab users
            "foundNum"  => 0,
            "new"       => [],  // Users in LDAP but not Gitlab
            "newNum"    => 0,
            "extra"     => [],  // Users in Gitlab but not LDAP
            "extraNum"  => 0,
            "update"    => [],  // Users in both LDAP and Gitlab
            "updateNum" => 0,
        ];

        // Find all existing Gitlab users
        $this->logger->notice("Finding all existing Gitlab users...");
        $p = 0;

        while (is_array($gitlabUsers = $gitlab->api("users")->all(["page" => ++$p, "per_page" => 100])) && !empty($gitlabUsers)) {
            foreach ($gitlabUsers as $i => $gitlabUser) {
                $n = $i + 1;

                if (!is_array($gitlabUser)) {
                    $this->logger->error(sprintf("User #%d: Not an array.", $n));
                    continue;
                }

                if (!isset($gitlabUser["id"])) {
                    $this->logger->error(sprintf("User #%d: Missing ID.", $n));
                    continue;
                }

                if (!($gitlabUserId = intval($gitlabUser["id"]))) {
                    $this->logger->error(sprintf("User #%d: Empty ID.", $n));
                    continue;
                }

                if (!isset($gitlabUser["username"])) {
                    $this->logger->error(sprintf("User #%d: Missing user name.", $n));
                    continue;
                }

                if (!($gitlabUserName = trim($gitlabUser["username"]))) {
                    $this->logger->error(sprintf("User #%d: Empty user name.", $n));
                    continue;
                }

                if ($this->in_array_i($gitlabUserName, $this->getBuiltInUserNames())) {
                    $this->logger->info(sprintf("Gitlab built-in %s user will be ignored.", $gitlabUserName));
                    continue;
                }

                $this->logger->info(sprintf("Found Gitlab user #%d \"%s\".", $gitlabUserId, $gitlabUserName));
                if (isset($usersSync["found"][$gitlabUserId]) || $this->in_array_i($gitlabUserName, $usersSync["found"])) {
                    $this->logger->warning(sprintf("Duplicate Gitlab user #%d \"%s\".", $gitlabUserId, $gitlabUserName));
                    continue;
                }

                $usersSync["found"][$gitlabUserId] = $gitlabUserName;
            }
        }

        asort($usersSync["found"]);
        $this->logger->notice(sprintf("%d Gitlab user(s) found.", $usersSync["foundNum"] = count($usersSync["found"])));

        // Create directory users of which don't exist in Gitlab
        $this->logger->notice("Creating directory users of which don't exist in Gitlab...");
        foreach ($ldapUsers as $ldapUserName => $ldapUserDetails) {
            if ($this->in_array_i($ldapUserName, $this->getBuiltInUserNames())) {
                $this->logger->info(sprintf("Gitlab built-in %s user will be ignored.", $ldapUserName));
                continue;
            }

            if ($this->in_array_i($ldapUserName, $config["gitlab"]["options"]["userNamesToIgnore"])) {
                $this->logger->info(sprintf("User \"%s\" in ignore list.", $ldapUserName));
                continue;
            }

            $gitlabUserName = trim($ldapUserName);
            if ($this->in_array_i($gitlabUserName, $usersSync["found"])) {
                continue;
            }

            $this->logger->info(sprintf("Creating Gitlab user \"%s\" [%s].", $gitlabUserName, $ldapUserDetails["dn"]));
            $gitlabUser = null;

            $gitlabUserPassword = $this->generateRandomPassword(12);
            $this->logger->debug(sprintf("Password for Gitlab user \"%s\" [%s] will be: %s", $gitlabUserName, $ldapUserDetails["dn"], $gitlabUserPassword));

            try {
                !$this->dryRun ? ($gitlabUser = $gitlab->api("users")->create($ldapUserDetails["email"], $gitlabUserPassword, [
                    "username"          => $gitlabUserName,
                    "reset_password"    => false,
                    "name"              => $ldapUserDetails["fullName"],
                    "extern_uid"        => $ldapUserDetails["dn"],
                    "provider"          => $gitlabConfig["ldapServerName"],
                    "email"             => $ldapUserDetails["email"],
                    "admin"             => $ldapUserDetails["isAdmin"],
                    "can_create_group"  => $ldapUserDetails["isAdmin"],
                    "skip_confirmation" => true,
                    "external"          => $ldapUserDetails["isExternal"],
                ])) : $this->logger->warning("Operation skipped due to dry run.");
            } catch (\Exception $e) {
                // Permit continue when user email address already used by another account
                if ("Email has already been taken" === $e->getMessage()) {
                    $this->logger->error(sprintf("Gitlab user \"%s\" [%s] was not created, email address already used by another account.", $gitlabUserName, $ldapUserDetails["dn"]));
                }

                if ($this->continueOnFail) {
                    $this->gitlabApiCoolDown();
                    continue;
                }

                throw $e;
            }

            $gitlabUserId = (is_array($gitlabUser) && isset($gitlabUser["id"]) && is_int($gitlabUser["id"])) ? $gitlabUser["id"] : sprintf("dry:%s", $ldapUserDetails["dn"]);
            $usersSync["new"][$gitlabUserId] = $gitlabUserName;

            $this->gitlabApiCoolDown();
        }

        asort($usersSync["new"]);
        $this->logger->notice(sprintf("%d Gitlab user(s) created.", $usersSync["newNum"] = count($usersSync["new"])));

        // Disable Gitlab users of which don't exist in directory
        $this->logger->notice("Disabling Gitlab users of which don't exist in directory...");
        foreach ($usersSync["found"] as $gitlabUserId => $gitlabUserName) {
            if ($this->in_array_i($gitlabUserName, $this->getBuiltInUserNames())) {
                $this->logger->info(sprintf("Gitlab built-in %s user will be ignored.", $gitlabUserName));
                continue;
            }

            if ($this->in_array_i($gitlabUserName, $config["gitlab"]["options"]["userNamesToIgnore"])) {
                $this->logger->info(sprintf("User \"%s\" in ignore list.", $gitlabUserName));
                continue;
            }

            if (isset($ldapUsers[$gitlabUserName]) && is_array($ldapUsers[$gitlabUserName])) {
                continue;
            }

            $this->logger->warning(sprintf("Disabling Gitlab user #%d \"%s\".", $gitlabUserId, $gitlabUserName));
            $gitlabUser = null;

            !$this->dryRun ? ($gitlabUser = $gitlab->api("users")->block($gitlabUserId)) : $this->logger->warning("Operation skipped due to dry run.");
            !$this->dryRun ? ($gitlabUser = $gitlab->api("users")->update($gitlabUserId, [
                "admin"             => false,
                "can_create_group"  => false,
                "external"          => true,
            ])) : $this->logger->warning("Operation skipped due to dry run.");

            $usersSync["extra"][$gitlabUserId] = $gitlabUserName;

            $this->gitlabApiCoolDown();
        }

        asort($usersSync["extra"]);
        $this->logger->notice(sprintf("%d Gitlab user(s) disabled.", $usersSync["extraNum"] = count($usersSync["extra"])));

        // Update users of which were already in both Gitlab and the directory
        $this->logger->notice("Updating users of which were already in both Gitlab and the directory...");
        foreach ($usersSync["found"] as $gitlabUserId => $gitlabUserName) {
            if (!empty($usersSync["new"][$gitlabUserId]) || !empty($usersSync["extra"][$gitlabUserId])) {
                continue;
            }

            if ($this->in_array_i($gitlabUserName, $this->getBuiltInUserNames())) {
                $this->logger->info(sprintf("Gitlab built-in %s user will be ignored.", $gitlabUserName));
                continue;
            }

            if ($this->in_array_i($gitlabUserName, $config["gitlab"]["options"]["userNamesToIgnore"])) {
                $this->logger->info(sprintf("User \"%s\" in ignore list.", $gitlabUserName));
                continue;
            }

            if ($gitlab->api("users")->all(["username" => $gitlabUserName, "blocked" => true])) {
                $this->logger->info(sprintf("Enabling Gitlab user #%d \"%s\".", $gitlabUserId, $gitlabUserName));
                $gitlabUser = null;
                !$this->dryRun ? ($gitlabUser = $gitlab->api("users")->unblock($gitlabUserId)) : $this->logger->warning("Operation skipped due to dry run.");
            }

            $this->logger->info(sprintf("Updating Gitlab user #%d \"%s\".", $gitlabUserId, $gitlabUserName));
            $gitlabUser = null;

            if (!isset($ldapUsers[$gitlabUserName]) || !is_array($ldapUsers[$gitlabUserName]) || count($ldapUsers[$gitlabUserName]) < 4) {
                $this->logger->info(sprintf("Gitlab user \"%s\" has no LDAP details available.", $gitlabUserName));
                continue;
            }
            $ldapUserDetails = $ldapUsers[$gitlabUserName];

            !$this->dryRun ? ($gitlabUser = $gitlab->api("users")->update($gitlabUserId, [
                // "username"          => $gitlabUserName,
                // No point updating that. ^
                // If the UID changes so will that bit of the DN anyway, so this can't be detected with a custom attribute containing the Gitlab user ID written back to user's LDAP object.
                "reset_password"    => false,
                "name"              => $ldapUserDetails["fullName"],
                "extern_uid"        => $ldapUserDetails["dn"],
                "provider"          => $gitlabConfig["ldapServerName"],
                "email"             => $ldapUserDetails["email"],
                "admin"             => $ldapUserDetails["isAdmin"],
                "can_create_group"  => $ldapUserDetails["isAdmin"],
                "skip_confirmation" => true,
                "external"          => $ldapUserDetails["isExternal"],
            ])) : $this->logger->warning("Operation skipped due to dry run.");

            $usersSync["update"][$gitlabUserId] = $gitlabUserName;

            $this->gitlabApiCoolDown();
        }

        asort($usersSync["update"]);
        $this->logger->notice(sprintf("%d Gitlab user(s) updated.", $usersSync["updateNum"] = count($usersSync["update"])));
        // >> Handle users

        // << Handle groups
        $groupsSync = [
            "found"     => [],  // All existing Gitlab groups
            "foundNum"  => 0,
            "new"       => [],  // Groups in LDAP but not Gitlab
            "newNum"    => 0,
            "extra"     => [],  // Groups in Gitlab but not LDAP
            "extraNum"  => 0,
            "update"    => [],  // Groups in both LDAP and Gitlab
            "updateNum" => 0,
        ];

        // Find all existing Gitlab groups
        $this->logger->notice("Finding all existing Gitlab groups...");
        $p = 0;

        while (is_array($gitlabGroups = $gitlab->api("groups")->all(["page" => ++$p, "per_page" => 100, "all_available" => true])) && !empty($gitlabGroups)) {
            foreach ($gitlabGroups as $i => $gitlabGroup) {
                $n = $i + 1;

                if (!is_array($gitlabGroup)) {
                    $this->logger->error(sprintf("Group #%d: Not an array.", $n));
                    continue;
                }

                if (!isset($gitlabGroup["id"])) {
                    $this->logger->error(sprintf("Group #%d: Missing ID.", $n));
                    continue;
                }

                if (!($gitlabGroupId = intval($gitlabGroup["id"]))) {
                    $this->logger->error(sprintf("Group #%d: Empty ID.", $n));
                    continue;
                }

                if (!isset($gitlabGroup["name"])) {
                    $this->logger->error(sprintf("Group #%d: Missing name.", $n));
                    continue;
                }

                if (!($gitlabGroupName = trim($gitlabGroup["name"]))) {
                    $this->logger->error(sprintf("Group #%d: Empty name.", $n));
                    continue;
                }

                if (!($gitlabGroupPath = trim($gitlabGroup["path"]))) {
                    $this->logger->error(sprintf("Group #%d: Empty path.", $n));
                    continue;
                }

                if ("Root" == $gitlabGroupName) {
                    $this->logger->info("Gitlab built-in root group will be ignored.");
                    continue; // The Gitlab root group should never be updated from LDAP.
                }

                if ("Users" == $gitlabGroupName) {
                    $this->logger->info("Gitlab built-in users group will be ignored.");
                    continue; // The Gitlab users group should never be updated from LDAP.
                }

                $this->logger->info(sprintf("Found Gitlab group #%d \"%s\" [%s].", $gitlabGroupId, $gitlabGroupName, $gitlabGroupPath));
                if (isset($groupsSync["found"][$gitlabGroupId]) || $this->in_array_i($gitlabGroupName, $groupsSync["found"])) {
                    $this->logger->warning(sprintf("Duplicate Gitlab group %d \"%s\" [%s].", $gitlabGroupId, $gitlabGroupName, $gitlabGroupPath));
                    continue;
                }

                $groupsSync["found"][$gitlabGroupId] = $gitlabGroupName;
            }
        }

        asort($groupsSync["found"]);
        $this->logger->notice(sprintf("%d Gitlab group(s) found.", $groupsSync["foundNum"] = count($groupsSync["found"])));

        // Create directory groups of which don't exist in Gitlab
        $this->logger->notice("Creating directory groups of which don't exist in Gitlab...");
        foreach ($ldapGroupsSafe as $ldapGroupName => $ldapGroupMembers) {
            if ("Root" == $ldapGroupName) {
                $this->logger->info("Gitlab built-in root group will be ignored.");
                continue; // The Gitlab root group should never be updated from LDAP.
            }

            if ("Users" == $ldapGroupName) {
                $this->logger->info("Gitlab built-in users group will be ignored.");
                continue; // The Gitlab users group should never be updated from LDAP.
            }

            if ($this->in_array_i($ldapGroupName, $config["gitlab"]["options"]["groupNamesToIgnore"])) {
                $this->logger->info(sprintf("Group \"%s\" in ignore list.", $ldapGroupName));
                continue;
            }

            $gitlabGroupName = $slugifyGitlabName->slugify($ldapGroupName);
            $gitlabGroupPath = $slugifyGitlabPath->slugify($ldapGroupName);
            if ($this->in_array_i($gitlabGroupName, $groupsSync["found"])) {
                continue;
            }

            if ((!is_array($ldapGroupMembers) || empty($ldapGroupMembers)) && !$config["gitlab"]["options"]["createEmptyGroups"]) {
                $this->logger->warning(sprintf("Not creating Gitlab group \"%s\" [%s]: No members in directory group, or config gitlab->options->createEmptyGroups is disabled.", $gitlabGroupName, $gitlabGroupPath));
                continue;
            }

            $this->logger->info(sprintf("Creating Gitlab group \"%s\" [%s].", $gitlabGroupName, $gitlabGroupPath));
            $gitlabGroup = null;

            !$this->dryRun ? ($gitlabGroup = $gitlab->api("groups")->create($gitlabGroupName, $gitlabGroupPath)) : $this->logger->warning("Operation skipped due to dry run.");

            $gitlabGroupId = (is_array($gitlabGroup) && isset($gitlabGroup["id"]) && is_int($gitlabGroup["id"])) ? $gitlabGroup["id"] : sprintf("dry:%s", $gitlabGroupPath);
            $groupsSync["new"][$gitlabGroupId] = $gitlabGroupName;

            $this->gitlabApiCoolDown();
        }

        asort($groupsSync["new"]);
        $this->logger->notice(sprintf("%d Gitlab group(s) created.", $groupsSync["newNum"] = count($groupsSync["new"])));

        // Delete Gitlab groups of which don't exist in directory
        $this->logger->notice("Deleting Gitlab groups of which don't exist in directory...");
        foreach ($groupsSync["found"] as $gitlabGroupId => $gitlabGroupName) {
            if ("Root" == $gitlabGroupName) {
                $this->logger->info("Gitlab built-in root group will be ignored.");
                continue; // The Gitlab root group should never be updated from LDAP.
            }

            if ("Users" == $gitlabGroupName) {
                $this->logger->info("Gitlab built-in users group will be ignored.");
                continue; // The Gitlab users group should never be updated from LDAP.
            }

            if ($this->in_array_i($gitlabGroupName, $config["gitlab"]["options"]["groupNamesToIgnore"])) {
                $this->logger->info(sprintf("Group \"%s\" in ignore list.", $gitlabGroupName));
                continue;
            }

            if ($this->array_key_exists_i($gitlabGroupName, $ldapGroupsSafe)) {
                continue;
            }
            $ldapGroupMembers = $ldapGroupsSafe[$gitlabGroupName];

            $gitlabGroupPath = $slugifyGitlabPath->slugify($gitlabGroupName);
            if ((is_array($ldapGroupMembers) && !empty($ldapGroupMembers)) || !$config["gitlab"]["options"]["deleteExtraGroups"]) {
                $this->logger->info(sprintf("Not deleting Gitlab group #%d \"%s\" [%s]: Has members in directory group, or config gitlab->options->deleteExtraGroups is disabled.", $gitlabGroupId, $gitlabGroupName, $gitlabGroupPath));
                continue;
            }

            if (is_array($gitlabGroupProjects = $gitlab->api("groups")->projects($gitlabGroupId)) && ($gitlabGroupProjectsNum = count($gitlabGroupProjects)) >= 1) {
                $this->logger->info(sprintf("Not deleting Gitlab group #%d \"%s\" [%s]: It contains %d project(s).", $gitlabGroupId, $gitlabGroupName, $gitlabGroupPath, $gitlabGroupProjectsNum));
                continue;
            }

            if (is_array($gitlabGroupSubGroups = $gitlab->api("groups")->subgroups($gitlabGroupId)) && ($gitlabGroupSubGroupsNum = count($gitlabGroupSubGroups)) >= 1) {
                $this->logger->info(sprintf("Not deleting Gitlab group #%d \"%s\" [%s]: It contains %d subgroup(s).", $gitlabGroupId, $gitlabGroupName, $gitlabGroupPath, $gitlabGroupSubGroupsNum));
                continue;
            }

            $this->logger->warning(sprintf("Deleting Gitlab group #%d \"%s\" [%s].", $gitlabGroupId, $gitlabGroupName, $gitlabGroupPath));
            $gitlabGroup = null;

            !$this->dryRun ? ($gitlabGroup = $gitlab->api("groups")->remove($gitlabGroupId)) : $this->logger->warning("Operation skipped due to dry run.");

            $groupsSync["extra"][$gitlabGroupId] = $gitlabGroupName;

            $this->gitlabApiCoolDown();
        }

        asort($groupsSync["extra"]);
        $this->logger->notice(sprintf("%d Gitlab group(s) deleted.", $groupsSync["extraNum"] = count($groupsSync["extra"])));

        // Update groups of which were already in both Gitlab and the directory
        $this->logger->notice("Updating groups of which were already in both Gitlab and the directory...");
        foreach ($groupsSync["found"] as $gitlabGroupId => $gitlabGroupName) {
            if (!empty($groupsSync["new"][$gitlabGroupId]) || !empty($groupsSync["extra"][$gitlabGroupId])) {
                continue;
            }

            if ("Root" == $gitlabGroupName) {
                $this->logger->info("Gitlab built-in root group will be ignored.");
                continue; // The Gitlab root group should never be updated from LDAP.
            }

            if ("Users" == $gitlabGroupName) {
                $this->logger->info("Gitlab built-in users group will be ignored.");
                continue; // The Gitlab users group should never be updated from LDAP.
            }

            if ($this->in_array_i($gitlabGroupName, $config["gitlab"]["options"]["groupNamesToIgnore"])) {
                $this->logger->info(sprintf("Group \"%s\" in ignore list.", $gitlabGroupName));
                continue;
            }

            $gitlabGroupPath = $slugifyGitlabPath->slugify($gitlabGroupName);

            $this->logger->info(sprintf("Updating Gitlab group #%d \"%s\" [%s].", $gitlabGroupId, $gitlabGroupName, $gitlabGroupPath));
            $gitlabGroup = null;

            if (!isset($ldapGroupsSafe[$gitlabGroupName]) || !is_array($ldapGroupsSafe[$gitlabGroupName])) {
                $this->logger->info(sprintf("Gitlab group \"%s\" has no LDAP details available.", $gitlabGroupName));
                continue;
            }
            $ldapGroupMembers = $ldapGroupsSafe[$gitlabGroupName];

            /*
            !$this->dryRun ? ($gitlabGroup = $gitlab->api("groups")->update($gitlabGroupId, [
                // "name"              => $gitlabGroupName,
                // No point updating that. ^
                // If the CN changes so will that bit of the DN anyway, so this can't be detected with a custom attribute containing the Gitlab group ID written back to group's LDAP object.
                "path"              => $gitlabGroupPath,
            ])) : $this->logger->warning("Operation skipped due to dry run.");
             */

            $groupsSync["update"][$gitlabGroupId] = $gitlabGroupName;

            /* Not required until group updates can be detected as per above.
            $this->gitlabApiCoolDown();
             */
        }

        asort($groupsSync["update"]);
        $this->logger->notice(sprintf("%d Gitlab group(s) updated.", $groupsSync["updateNum"] = count($groupsSync["update"])));
        // >> Handle groups

        // Build user/group synchronisation lists (for group membership, SSH keys, etc.)
        $usersToSync        = ($usersSync["found"] + $usersSync["new"] + $usersSync["update"]);
        $usersToSyncNum     = count($usersToSync);
        asort($usersToSync);

        $groupsToSync       = ($groupsSync["found"] + $groupsSync["new"] + $groupsSync["update"]);
        $groupsToSyncNum    = count($groupsToSync);
        asort($groupsToSync);

        $this->logger->info(sprintf("%d user(s) and %d group(s) in the synchronisation lists.", $usersToSyncNum, $groupsToSyncNum));

        // << Handle user SSH keys
        /* Feature TBD :)
        foreach ($usersToSync as $gitlabUserId => $gitlabUserName) {
            $ldapUserSshKeys    = [];
            $gitlabUserSshKeys  = $gitlab->api("users")->userKeys($gitlabUserId);
        }
         */
        // >> Handle user SSH keys

        // << Handle group memberships
        $this->logger->notice("Synchronising Gitlab group members with directory group members...");
        foreach ($groupsToSync as $gitlabGroupId => $gitlabGroupName) {
            if ("Root" == $gitlabGroupName) {
                $this->logger->info("Gitlab built-in root group will be ignored.");
                continue; // The Gitlab root group should never be updated from LDAP.
            }

            if ("Users" == $gitlabGroupName) {
                $this->logger->info("Gitlab built-in users group will be ignored.");
                continue; // The Gitlab users group should never be updated from LDAP.
            }

            if ($this->in_array_i($gitlabGroupName, $config["gitlab"]["options"]["groupNamesToIgnore"])) {
                $this->logger->info(sprintf("Group \"%s\" in ignore list.", $gitlabGroupName));
                continue;
            }

            $gitlabGroupPath = $slugifyGitlabPath->slugify($gitlabGroupName);

            $membersOfThisGroup = [];
            foreach ($usersToSync as $gitlabUserId => $gitlabUserName) {
                if (!isset($ldapGroupsSafe[$gitlabGroupName]) || !is_array($ldapGroupsSafe[$gitlabGroupName])) {
                    $this->logger->warning(sprintf("Group \"%s\" doesn't appear to exist at path \"%s\". (Is this a sub-group? Sub-groups are not supported yet.)", $gitlabGroupName, $gitlabGroupPath));
                    continue;
                }

                if (!$this->in_array_i($gitlabUserName, $ldapGroupsSafe[$gitlabGroupName])) {
                    continue;
                }

                $membersOfThisGroup[$gitlabUserId] = $gitlabUserName;
            }
            asort($membersOfThisGroup);
            $this->logger->notice(sprintf("Synchronising %d member(s) for group #%d \"%s\" [%s]...", ($membersOfThisGroupNum = count($membersOfThisGroup)), $gitlabGroupId, $gitlabGroupName, $gitlabGroupPath));

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
            $this->logger->notice("Finding existing group members...");
            $p = 0;

            while (is_array($gitlabUsers = $gitlab->api("groups")->members($gitlabGroupId, ["page" => ++$p, "per_page" => 100])) && !empty($gitlabUsers)) {
                foreach ($gitlabUsers as $i => $gitlabUser) {
                    $n = $i + 1;

                    if (!is_array($gitlabUser)) {
                        $this->logger->error(sprintf("Group member #%d: Not an array.", $n));
                        continue;
                    }

                    if (!isset($gitlabUser["id"])) {
                        $this->logger->error(sprintf("Group member #%d: Missing ID.", $n));
                        continue;
                    }

                    if (!($gitlabUserId = intval($gitlabUser["id"]))) {
                        $this->logger->error(sprintf("Group member #%d: Empty ID.", $n));
                        continue;
                    }

                    if (!isset($gitlabUser["username"])) {
                        $this->logger->error(sprintf("Group member #%d: Missing user name.", $n));
                        continue;
                    }

                    if (!($gitlabUserName = trim($gitlabUser["username"]))) {
                        $this->logger->error(sprintf("Group member #%d: Empty user name.", $n));
                        continue;
                    }

                    if ($this->in_array_i($gitlabUserName, $this->getBuiltInUserNames())) {
                        $this->logger->info(sprintf("Gitlab built-in %s user will be ignored.", $gitlabUserName));
                        continue;
                    }

                    $this->logger->info(sprintf("Found Gitlab group member #%d \"%s\".", $gitlabUserId, $gitlabUserName));
                    if (isset($userGroupMembersSync["found"][$gitlabUserId]) || $this->in_array_i($gitlabUserName, $userGroupMembersSync["found"])) {
                        $this->logger->warning(sprintf("Duplicate Gitlab group member #%d \"%s\".", $gitlabUserId, $gitlabUserName));
                        continue;
                    }

                    $userGroupMembersSync["found"][$gitlabUserId] = $gitlabUserName;
                }
            }

            asort($userGroupMembersSync["found"]);
            $this->logger->notice(sprintf("%d Gitlab group \"%s\" [%s] member(s) found.", $userGroupMembersSync["foundNum"] = count($userGroupMembersSync["found"]), $gitlabGroupName, $gitlabGroupPath));

            // Add missing group members
            $this->logger->notice("Adding missing group members...");
            foreach ($membersOfThisGroup as $gitlabUserId => $gitlabUserName) {
                if (isset($userGroupMembersSync["found"][$gitlabUserId]) && $userGroupMembersSync["found"][$gitlabUserId] == $gitlabUserName) {
                    continue;
                }

                if (!isset($membersOfThisGroup[$gitlabUserId]) || $membersOfThisGroup[$gitlabUserId] != $gitlabUserName) {
                    continue;
                }

                $this->logger->info(sprintf("Adding user #%d \"%s\" to group #%d \"%s\" [%s].", $gitlabUserId, $gitlabUserName, $gitlabGroupId, $gitlabGroupName, $gitlabGroupPath));
                $gitlabGroupMember = null;

                !$this->dryRun ? ($gitlabGroupMember = $gitlab->api("groups")->addMember($gitlabGroupId, $gitlabUserId, $config["gitlab"]["options"]["newMemberAccessLevel"])) : $this->logger->warning("Operation skipped due to dry run.");

                $gitlabGroupMemberId = (is_array($gitlabGroupMember) && isset($gitlabGroupMember["id"]) && is_int($gitlabGroupMember["id"])) ? $gitlabGroupMember["id"] : sprintf("dry:%s:%d", $gitlabGroupPath, $gitlabUserId);
                $userGroupMembersSync["new"][$gitlabUserId] = $gitlabUserName;

                $this->gitlabApiCoolDown();
            }

            asort($userGroupMembersSync["new"]);
            $this->logger->notice(sprintf("%d Gitlab group \"%s\" [%s] member(s) added.", $userGroupMembersSync["newNum"] = count($userGroupMembersSync["new"]), $gitlabGroupName, $gitlabGroupPath));

            // Delete extra group members
            $this->logger->notice("Deleting extra group members...");
            foreach ($userGroupMembersSync["found"] as $gitlabUserId => $gitlabUserName) {
                if (isset($membersOfThisGroup[$gitlabUserId]) && $membersOfThisGroup[$gitlabUserId] == $gitlabUserName) {
                    continue;
                }

                $this->logger->info(sprintf("Deleting user #%d \"%s\" from group #%d \"%s\" [%s].", $gitlabUserId, $gitlabUserName, $gitlabGroupId, $gitlabGroupName, $gitlabGroupPath));
                $gitlabGroupMember = null;

                !$this->dryRun ? ($gitlabGroup = $gitlab->api("groups")->removeMember($gitlabGroupId, $gitlabUserId)) : $this->logger->warning("Operation skipped due to dry run.");

                $userGroupMembersSync["extra"][$gitlabUserId] = $gitlabUserName;

                $this->gitlabApiCoolDown();
            }

            asort($userGroupMembersSync["extra"]);
            $this->logger->notice(sprintf("%d Gitlab group \"%s\" [%s] member(s) deleted.", $userGroupMembersSync["extraNum"] = count($userGroupMembersSync["extra"]), $gitlabGroupName, $gitlabGroupPath));

            // Update existing group members
            /* This isn't needed...
            $this->logger->notice("Updating existing group members...");
            foreach ($userGroupMembersSync["found"] as $gitlabUserId => $gitlabUserName) {
                if ((isset($userUserMembersSync["new"][$gitlabUserId]) && $userUserMembersSync["new"][$gitlabUserId]) == $gitlabUserName || (isset($userUserMembersSync["extra"][$gitlabUserId]) && $userUserMembersSync["extra"][$gitlabUserId] == $gitlabUserName)) {
                    continue;
                }

                if (!isset($membersOfThisGroup[$gitlabUserId]) || $membersOfThisGroup[$gitlabUserId] != $gitlabUserName) {
                    continue;
                }

                $this->logger->info(sprintf("Updating user #%d \"%s\" in group #%d \"%s\" [%s].", $gitlabUserId, $gitlabUserName, $gitlabGroupId, $gitlabGroupName, $gitlabGroupPath));
                $gitlabGroupMember = null;

                !$this->dryRun ? ($gitlabGroupMember = $gitlab->api("groups")->saveMember($gitlabGroupId, $gitlabUserId, $config["gitlab"]["options"]["newMemberAccessLevel"])) : $this->logger->warning("Operation skipped due to dry run.");

                $userGroupMembersSync["update"][$gitlabUserId] = $gitlabUserName;
            }

            asort($userGroupMembersSync["update"]);
            $this->logger->notice(sprintf("%d Gitlab group \"%s\" [%s] member(s) updated.", $userGroupMembersSync["updateNum"] = count($userGroupMembersSync["update"]), $gitlabGroupName, $gitlabGroupPath));

            $this->gitlabApiCoolDown();
             */
        }
        // >> Handle group memberships

        // Disconnect
        $this->logger->debug("Gitlab: Unbinding");
        $gitlab = null;

        $this->logger->notice("Gitlab connection closed.");
    }

    /**
     * Case-insensitive `in_array()`.
     * @param  bool|int|float|string $needle
     * @param  array<mixed>          $haystack
     * @return bool
     */
    private function in_array_i($needle, array $haystack): bool
    {
        if ("" === ($needle = strtolower(strval($needle)))) {
            throw new \UnexpectedValueException("Needle not specified.");
        }

        return in_array($needle, array_map("strtolower", $haystack));
    }

    /**
     * Case insensitive `array_key_exists()`.
     * @param  bool|int|float|string $key
     * @param  array<mixed>          $haystack
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
     * @param  int    $length Length
     * @return string         Password
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
     * @return array<string>
     */
    private function getBuiltInUserNames()
    {
        return ["root", "ghost", "support-bot", "alert-bot"];
    }

    /**
     * Wait a bit of time between each Gitlab API request to avoid HTTP 500 errors when doing too many requests in a short time.
     * @return void
     */
    private function gitlabApiCoolDown(): void
    {
        if ($this->dryRun) {
            return; // Not required for dry runs
        }

        usleep(self::API_COOL_DOWN_USECONDS);
    }
}
