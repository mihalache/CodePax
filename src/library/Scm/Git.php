<?php

/**
 * CodePax
 *
 * LICENSE
 *
 * This source file is subject to the New BSD license that is bundled
 * with this package in the file LICENSE
 * It is also available through the world-wide-web at this URL:
 * http://www.codepax.com/license.html
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@codepax.com so we can send you a copy immediately.immediately
 * */

/**
 * Performs Git operations like: pull, fetch, push, checkout, git status, etc.
 *
 * Used especially for testing, when needed to test a separate branch or tag
 * and keep the master untouched. Also used for updating the working copy to
 * a desired revison or HEAD revision.
 *
 * Important: this class requires a Git command line client
 * to be installed on the runnig machine
 *
 * @category CodePax
 * @subpackage Git
 * @copyright Copyright (c) 2013 Zitec COM srl, Romania
 * @license New BSD http://www.codepax.com/license.html
 * */
class CodePax_Scm_Git extends CodePax_Scm_Abstract
{

    /**
     * Connection string; contains path to GIT binaries, username and password
     *
     * @var string
     * */
    protected $git_connection_string;

    /**
     * Repository info
     *
     * @var string
     * */
    protected $git_info = null;
    protected $top_info = array();
    protected $more_info = array();

    /**
     * Repository URL
     *
     * @var string
     * */
    protected $git_url;

    /**
     * Git connections error messages
     *
     * @var string
     * */
    protected $git_error_message = null;

    /**
     * Full path to GIT binaries. Can be
     * overwritten from constructor
     * For Win this should be
     * "C:\Program Files (x86)\Git\bin\git.exe"
     * - OR -
     * "C:\Program Files\Git\bin\git.exe"
     *
     * @var string
     * */
    protected $path_to_git_bin = '/usr/bin/git';

    /**
     * Class constructor
     *
     * @param string $_git_user svn user
     * @param string $_git_pass svn pass
     * @param string $_git_url url to repository
     * @param string $_project_folder project folder
     * */
    public function __construct($_git_user, $_git_pass, $_git_url, $_project_folder)
    {
        parent::__construct();
        if (defined('PATH_TO_GIT_BIN')) {
            $this->path_to_git_bin = PATH_TO_GIT_BIN;
        }

        $this->project_folder = $_project_folder;

        if ($this->is_windows) {
            $this->path_to_git_bin = "\"{$this->path_to_git_bin}\"";
            $this->project_folder = "\"{$_project_folder}\"";
        }

        $this->git_connection_string = "cd {$this->project_folder}{$this->command_separator}{$this->path_to_git_bin}";

        $this->checkLocalConfig();

        $this->checkRemoteConfig($_git_user, $_git_pass);

        $this->remoteUpdate();

        //--- set repository info
        $shell_command = $this->git_connection_string . " log --max-count=1 " . self::GET_RESULT_DIRECTIVE;
        $response_string = shell_exec($shell_command);

        if (isset($response_string)) {
            $this->git_info = $response_string;
        }
    }

    /**
     * Check if the origin url contains username and password
     * If not, create new url with username and password
     *
     * @param string $_git_user scm username
     * @param string $_git_pass
     *
     * @return void
     * */
    protected function checkRemoteConfig($_git_user, $_git_pass)
    {

        $shell_command_update = $this->git_connection_string . ' config remote.origin.url ' . self::GET_RESULT_DIRECTIVE;
        $remote_url = shell_exec($shell_command_update);
        $parsed_url = parse_url(trim($remote_url));

        if (array_key_exists('user', $parsed_url) === false || array_key_exists('pass', $parsed_url) === false ||
            $parsed_url['user'] != $_git_user || $parsed_url['pass'] != $_git_pass
        ) {
            $new_url = $parsed_url['scheme'] . '://' . $_git_user . ':' . $_git_pass . '@' . $parsed_url['host'] . $parsed_url['path'];
            $update_remote_url = $this->git_connection_string . ' remote set-url origin ' . $new_url . ' ' . self::GET_RESULT_DIRECTIVE;

            shell_exec($update_remote_url);
        }
    }

    /**
     * Fetch updates from remote origin
     */
    protected function remoteUpdate()
    {
        $shell_command_update = "cd \"{$this->project_folder}\" ;" . $this->path_to_git_bin . ' remote update ' . self::GET_RESULT_DIRECTIVE;
        $update_response = shell_exec($shell_command_update);

        if (is_numeric(strpos($update_response, "error: Could not"))) {
            $this->setError($update_response);
        }
    }

    /**
     * Check if local folder is a git
     * repository
     */
    protected function checkLocalConfig()
    {
//check if the repo is a git repository
        $shell_command_status = $this->git_connection_string . ' status ' . self::GET_RESULT_DIRECTIVE;
        $git_status = shell_exec($shell_command_status);
        if (is_numeric(strpos($git_status, "fatal: "))) {
            $this->setError($git_status);
        }
    }

    /**
     * Set branches array; both active and merged branches
     *
     * @return void
     * */
    protected function setBranches()
    {
        if (empty($this->branches)) {
            $shell_command = "{$this->git_connection_string} branch -r " . self::GET_RESULT_DIRECTIVE;
            $response_string = shell_exec($shell_command);

            $this->branches = array_map('trim', explode("\n", $response_string));
            //--- pop the last value since it is empty
            if (count($this->branches) > 1) {
                array_pop($this->branches);
            }

            //get the key for the branch used as master
            $masterKey = array_search("origin/" . SCM_STABLE_NAME, $this->branches);
            //remove the master from active branches
            unset($this->branches[$masterKey]);

            //remove the false HEAD branch
            $this->branches = preg_grep('/^origin\/HEAD/',$this->branches,1);

            foreach ($this->branches as $name) {
                if (substr($name, 0, 9) == 'origin/' . MERGED_BRANCH_MARKER) {
                    $this->merged_branches[] = trim(str_replace('origin/', '', $name));
                } else {
                    $this->active_branches[] = trim(str_replace('origin/', '', $name));
                }
            }
        }
    }

    /**
     * Get active branches
     *
     * @return array
     * */
    public function getActiveBranches()
    {
        $this->setBranches();
        return $this->active_branches;
    }

    /**
     * Get merged branches
     *
     * @return array
     * */
    public function getMergedBranches()
    {
        $this->setBranches();
        return $this->merged_branches;
    }

    /**
     * Get all branches, both merged and active
     *
     * @return array
     * */
    public function getBranches()
    {
        $this->setBranches();
        return $this->branches;
    }

    /**
     * Get tags
     *
     * @return array
     * */
    public function getTags()
    {
        $shell_command = "{$this->git_connection_string} tag -l ";
        $response_string = shell_exec($shell_command);
        $res = array_map('trim', explode("\n", str_replace('/', '', $response_string)));
        //popout the last value since it is empty
        array_pop($res);
        return $res;
    }

    /**
     * Get the list with local branches
     *
     */
    public function getLocalBranches()
    {
        $shell_command = "{$this->git_connection_string} branch " . self::GET_RESULT_DIRECTIVE;
        $response_string = shell_exec($shell_command);

        $local_branches = array_map('trim', explode("\n", $response_string));

        //search in list after current branch - the branch that is marked with an *
        $current_branch_key = array_search('* ' . $this->getCurrentPosition(), $local_branches);

        //replace the branch name in order to remove the *
        $local_branches[$current_branch_key] = $this->getCurrentPosition();

        $this->local_branches = $local_branches;
    }

    /**
     * Switches the working copy to specified REMOTE branch
     * NOTE: this will also create a local branch
     * @param string $name
     * @return string
     * */
    public function switchToBranch($name)
    {
        $this->getLocalBranches();

        if (in_array($name, $this->local_branches)) {
            // switch to branch
            $shell_command = "{$this->git_connection_string} checkout {$name} " . self::GET_RESULT_DIRECTIVE;
            shell_exec($shell_command);

            //update branch
            return $this->updateCurrentBranch($name);
        } else {
            $shell_command = "{$this->git_connection_string} checkout -b {$name} origin/" . $name . " " . self::GET_RESULT_DIRECTIVE;
            return shell_exec($shell_command);
        }
    }

    /**
     * update the working branch
     * @param string @name current branch
     * $return string
     */
    public function updateCurrentBranch($name)
    {
        $update_command = "{$this->git_connection_string} pull origin " . $name . self::GET_RESULT_DIRECTIVE;
        return shell_exec($update_command);
    }

    /**
     * Switches the working copy to specified tag
     *
     * @param string $_name
     * @return string
     * */
    public function switchToTag($name)
    {
        return self::switchToRemoteBranch($name);
    }

    /**
     * Switches the working copy to master
     * NOTE: kept this for compatibility purposes
     * @return string
     * */
    public function switchToTrunk()
    {
        return self::switchToBranch(SCM_STABLE_NAME);
    }

    /**
     * Switches the working copy to the supplied revision; if none is given will switch to HEAD revision
     *
     * @param int $_revision_no revision to switch to
     * @return string
     * */
    public function switchToRevision($_revision_no = null)
    {
        return self::switchToBranch($_revision_no);
    }

    /**
     * Gets info about slected project
     *
     * The info may be: revision number, last author, modified at, etc.
     *
     * @return string
     * */
    public function getRepoInfo()
    {
        //config --get remote.origin.url
        list($revisionArray, $authorArray, $lastDateArray) = explode("\n", $this->git_info);

        list(, $revisionString) = explode(' ', $revisionArray, 2);
        list(, $authorString) = explode(' ', $authorArray, 2);
        list(, $lastDateString) = explode(' ', $lastDateArray, 2);
        $current_branch = $this->getCurrentPosition();

        $this->top_info = array_merge(
            $this->top_info,
            array(
                "Branch" => $current_branch,
                "Revision" => $revisionString,
                "Author" => $authorString,
                "Last changed" => trim($lastDateString)
            )
        );
        $this->more_info = array_merge(
            $this->more_info,
            array(
                "Path" => $this->project_folder,
                "Working Copy Root Path" => $this->project_folder,
                //"Relative URL" => "^/branches/20130904_userdata_flow",
                "Repository Root" => isset($this->top_info["URL"]) ? $this->top_info["URL"] : "",
                //"Repository UUID" => "4f0209ba-6557-4861-b6de-cf4b6729d2b8",
                "Node Kind" => "directory",
                "Schedule" => "normal"
            )
        );

        return $this->git_info;
    }

    public function getRepoTopInfo()
    {
        return $this->top_info;
    }

    public function getRepoMoreInfo()
    {
        return $this->more_info;
    }

    /**
     * Return the difference between branch revision
     * and stable line revision
     *
     * @return integer
     * */
    public function getBranchStatus()
    {
        if ($this->getCurrentPosition() == SCM_STABLE_NAME) {
            return false;
        }

        $scm_command_beyond = "{$this->git_connection_string} rev-list ..origin/" . SCM_STABLE_NAME . " --count " . self::GET_RESULT_DIRECTIVE;
        $scm_command_ahead = "{$this->git_connection_string} rev-list origin/" . SCM_STABLE_NAME . ".. --count " . self::GET_RESULT_DIRECTIVE;
        $output_behind = trim(shell_exec($scm_command_beyond));
        $output_ahead = trim(shell_exec($scm_command_ahead));

        $and = null;
        $output_ahead == 0 ? $ahead_message = '' : $ahead_message = $output_ahead . ' revision(s) ahead';
        $output_behind == 0 ? $behind_message = '' : $behind_message = $output_behind . ' revision(s) behind';

        if (!empty($ahead_message) && !empty($behind_message)) {
            $and = ' and ';
        }

        return 'This branch is ' . $behind_message . $and . $ahead_message . ' \'' . SCM_STABLE_NAME . '\'';
    }

    /**
     * Get name of the branch or tag is reading from.
     * When reading from trunk the method will return null
     *
     * @return string|null
     * */
    public function getCurrentPosition()
    {
        $shell_command = "{$this->git_connection_string} rev-parse --abbrev-ref HEAD " . self::GET_RESULT_DIRECTIVE;
        return trim(shell_exec($shell_command));
    }

    /**
     * Add a file or a set of files to repository
     *
     * @param string $_path relative to repo root
     * @return string
     * */
    public function add($_path)
    {
        $shell_command = "cd {$this->project_folder}" . $this->command_separator;
        $shell_command .= "{$this->path_to_git_bin} --force add {$_path} " . self::GET_RESULT_DIRECTIVE;
        return shell_exec($shell_command);
    }

    /**
     * Commit one or more files to repository
     *
     * @param string $_message commit message
     * @return string
     * */
    public function commit($_message)
    {
        $shell_command = "cd {$this->project_folder}" . $this->command_separator;
        $shell_command .= "{$this->git_connection_string} commit --message \"{$_message}\" " . self::GET_RESULT_DIRECTIVE;

        return shell_exec($shell_command);
    }

    /**
     * Add a file to repo and then commit it
     *
     * @param string $_message commit message
     * @param string $_path
     * @return string
     * */
    public function addAndCommit($_message, $_path)
    {
        $this->add($_path);
        return $this->commit($_message);
    }

    protected function setError($message)
    {
        throw new Exception($message);
    }
}
