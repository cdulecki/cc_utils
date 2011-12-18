<?php
// For wunderlist api, reference:
// http://fritz-grimpen.de/wunderlist/api.html
// https://github.com/fritz0705/ruby-wunderlist/blob/master/lib/wunderlist/api.rb

class Wunderlist
{
    protected $_wlUrl  = 'http://www.wunderlist.com';
    protected $_cookie = null;
    protected $_lists  = null;

    /**
     * Sets the cookie
     */
    public function setCookie($cookie)
    {
        $this->_cookie = $cookie;
    }

    /**
     * Gets the cookie
     */
    public function getCookie()
    {
        return $this->_cookie;
    }

    /**
     * Performs curl command; adds cookie if present and returntransfer option if needed
     *
     * @param  arary  $options CURL_XXXXX options
     * @return false on error, otherwise the data returned from curl
     */
    protected function _curl($options)
    {
        if (isset($options[CURLOPT_RETURNTRANSFER]) === false) {
            $options[CURLOPT_RETURNTRANSFER] = 1;
        }

        if (isset($options[CURLOPT_COOKIE]) === false and empty($this->_cookie) === false) {
            $options[CURLOPT_COOKIE] = 'WLSESSID=' . $this->_cookie;
        }

        $ch = curl_init();
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, $options);
        $rv = curl_exec($ch);
        curl_close($ch);
        return $rv;
    }

    /**
     * Authenticates to wunderlist for use of their ajax api
     *
     * @param  string $username Email of wunderlist user
     * @param  string $password Password of wunderlist user
     * @param  bool   $pwIsEncrypted False=pw is cleartext; true=pw is output of md5(password)
     * @throws Exception On error
     * @return void
     */
    public function authenticate($username, $password, $pwIsEncrypted = false)
    {
        if (empty($this->_cookie) === true) {
            $this->_getCookie();
        }

        $this->_login($username, $password, $pwIsEncrypted);
    }

    /**
     * Calls Wunderlist to establish a cookie
     *
     * @param  string $username Email of wunderlist user
     * @param  string $password Password of wunderlist user
     * @throws Exception On error
     * @return void
     */
    protected function _getCookie()
    {
        $rv = $this->_curl(array(CURLOPT_URL => $this->_wlUrl, CURLOPT_HEADER => 1));
        if ($rv === false) {
            throw new Exception('Error getting cookie from Wunderlist site');
        }

        $lines = explode("\n", $rv);
        foreach ($lines as $line) {
            if (substr($line, 0, 12) === 'Set-Cookie: ') {
                $cookieLine = $line;
                break;
            }
        }

        if (empty($cookieLine) === true) {
            throw new Exception('No cookie returned in http response header');
        }

        $cookie = str_replace('Set-Cookie: WLSESSID=', '', $cookieLine);
        $arr = explode(';', $cookie);
        $this->_cookie = $arr[0];
    }

    /**
     * Logs a user into wunderlist
     *
     * @param  string $username Email of wunderlist user
     * @param  string $password Password of wunderlist user
     * @param  bool   $pwIsEncrypted False=pw is cleartext; true=pw is output of md5(password)
     * @throws Exception On error
     * @return void
     */
    protected function _login($username, $password, $pwIsEncrypted = false)
    {
        if ($pwIsEncrypted === false) {
            $password = md5($password);
        }

        $rv = $this->_curl(array(CURLOPT_URL  => $this->_wlUrl . '/ajax/user',
                                 CURLOPT_POST => 1,
                                 CURLOPT_POSTFIELDS => 'email=' . $username . '&password=' . $password));
        if ($rv === false) {
            throw new Exception('Error logging in to Wunderlist site');
        }

        $response = json_decode($rv);
        if (is_null($response) === true) {
            throw new Exception('Error decoding login response');
        }

        if (isset($response->code) === false) {
            throw new Exception('Error, json format invalid; code missing');
        }

        if ($response->code !== 200) {
            throw new Exception('Login failed, response code=' . $response->code);
        }
    }

    /**
     * Gets the list of lists from Wunderlist
     *
     * @throws Exception on error
     * @return Array of lists
     */
    public function loadLists()
    {
        $rv = $this->_curl(array(CURLOPT_URL  => $this->_wlUrl . '/ajax/lists/all'));
        if ($rv === false) {
            throw new Exception('Error getting lists');
        }

        $lists = json_decode($rv);
        if (is_object($lists) === false) {
            throw new Exception('Error decoding lists');
        }

        if (isset($lists->status) === false) {
            throw new Exception('Error getting lists, status field not present in response');
        }

        if ($lists->status !== 'success') {
            throw new Exception('Error getting lists, status=[' . $lists->status . ']');
        }

        if (isset($lists->data) === false) {
            throw new Exception('Error getting lists, data member not present');
        }

        $this->_lists = $lists->data;
    }

    /**
     * Gets a list object from the current array loaded in this object
     *
     * @param  string $listName Name of list to fetch
     * @throws Exception On error
     * @return Standard object which contains list data
     */
    public function getListObject($listName)
    {
        if (empty($this->_lists) === true) {
            $this->loadLists();
        }

        foreach ($this->_lists as $list) {
            if (strcmp($list->name, $listName) === 0) {
                $listObj = $list;
                break;
            }
        }

        if (empty($listObj) === true) {
            throw new Exception('List not found: ' . $listName);
        }

        return $listObj;
    }

    /**
     * Returns an array of tasks for a list
     *
     * @param  string $listName Name of list to fetch
     * @throws Exception On error
     * @return Array of task descriptions (strings)
     */
    public function getTasksForList($listName)
    {
        $listObj = $this->getListObject($listName);

        $rv = $this->_curl(array(CURLOPT_URL  => $this->_wlUrl . '/ajax/lists/id/' . $listObj->online_id));
        if ($rv === false) {
            throw new Exception('Error getting tasks for list ' . $listName);
        }

        $data = json_decode($rv);
        if (is_object($data) === false) {
            throw new Exception('Error decoding data from get task request');
        }

        if (isset($data->status) === false) {
            throw new Exception('Error getting tasks, status field not present in response');
        }

        if ($data->status !== 'success') {
            throw new Exception('Error getting tasks, status=[' . $data->status . ']');
        }

        if (isset($data->data) === false) {
            throw new Exception('Error getting tasks, data member not present');
        }

        $tasks = array();
        $dom = new DOMDocument();
        @$dom->loadHTML($data->data);
        $htmlLis = $dom->getElementsByTagName('li');
        for ($idxLi = 0; $idxLi < $htmlLis->length; $idxLi++) {
            $newEntry = array();

            // SAMPLE:
            // <li class='more' rel='29602868' id='52885178'>
            //     <div class='checkboxcon'>
            //         <input tabIndex='-1' class='input-checked' type='checkbox' checked='checked'/>
            //     </div>
            //     <span class="icon fav" title="No prioritization"></span>  ---- OR ----  <span class="icon favina" title="Prioritize">
            //     <span class="description">Mow Grass</span>
            //     <input type="hidden" class="datepicker" title="Choose Date" value="0"/> ---- OR ---- <span class="showdate timestamp" rel="1322283600">11/26/2011</span>
            //     <span class="icon delete" title="Delete Task"></span>
            //     <span class="icon note" title="Show note"></span>  ---- OR ----  <span class="icon note activenote" title="Show note">active note text</span>
            // </li>

            // Get task id from LI element
            $li = $htmlLis->item($idxLi);
            $newEntry['id'] = $li->attributes->getNamedItem('id')->value;
            $newEntry['dueDateEpoch'] = 0;
            $newEntry['favorited'] = false;
            $newEntry['note'] = '';
            $newEntry['checked'] = false;

            for ($idxLiChild = 0; $idxLiChild < $li->childNodes->length; $idxLiChild++) {
                $tag = $li->childNodes->item($idxLiChild);
                $tagName = $tag->nodeName;
                if ($tagName === 'div') {
                    $value = $tag->firstChild->getAttribute('checked');
                    if (empty($value) === false) {
                        $newEntry['checked'] = true;
                    }
                } else if ($tagName === 'span') {
                    $class = $tag->getAttribute('class');
                    if ($class === 'description') {
                        $newEntry['description'] = $tag->nodeValue;
                    } else if ($class === 'icon note activenote') {
                        $newEntry['note'] = $tag->nodeValue;
                    } else if ($class === 'showdate timestamp') {
                        $newEntry['dueDateEpoch'] = $tag->getAttribute('rel');
                        $newEntry['dueDatePrintable'] = date('Y/m/d', $newEntry['dueDateEpoch'] + (60*60*5));   // adjust for pacific time
                    } else if ($class === 'icon fav') {
                        $newEntry['favorited'] = true;
                    }
                }
            }

            $tasks[] = $newEntry;
        }

        return $tasks;
    }

    /**
     * Add a tasks to the specified list
     *
     * @param  string $desc     Description of task
     * @param  string $listName Name of list to add task to
     * @throws Exception On error
     * @return void
     */
    public function addTask($desc, $listName) {
        $listObj  = $this->getListObject($listName);
        $postVars = array('list_id' => $listObj->online_id,
                          'name'    => urlencode($desc),
                          'date'    => 0);
        $rv       = $this->_curl(array(CURLOPT_URL  => $this->_wlUrl . '/ajax/tasks/insert',
                                       CURLOPT_POST => 1,
                                       CURLOPT_POSTFIELDS => 'task=' . json_encode($postVars)));

        $data = json_decode($rv);
        if (is_object($data) === false) {
            throw new Exception('WARNING: Issue decoding data from add task request; task MAY NOT have been added');
        }

        if (isset($data->status) === false) {
            throw new Exception('WARNING: Status field not present in add task response; task MAY NOT have been added');
        }

        if ($data->status !== 'success') {
            throw new Exception('Error adding task, status=[' . $data->status . ']');
        }
    }

    /**
     * Change priority of the of the task
     *
     * @param  array $taskId      Task id to update
     * @param  bool  $newPriority True (high priority) or false (normal)
     * @return void
     */
    public function changeTaskPriority($taskId, $newPriority)
    {
        $postVars = array('id' => $taskId,
                          'important' => ($newPriority === true ? 1 : 0));
        $rv       = $this->_curl(array(CURLOPT_URL  => $this->_wlUrl . '/ajax/tasks/update',
                                       CURLOPT_POST => 1,
                                       CURLOPT_POSTFIELDS => 'task=' . json_encode($postVars)));

        $data = json_decode($rv);
        if (is_object($data) === false) {
            throw new Exception('WARNING: Issue decoding data from change Task Priority request; task MAY NOT have been added');
        }

        if (isset($data->status) === false) {
            throw new Exception('WARNING: Status field not present in change Task Priority response; task MAY NOT have been added');
        }

        if ($data->status !== 'success') {
            throw new Exception('Error changing task priority, status=[' . $data->status . ']');
        }
    }

    /**
     * Deletes a task from a list
     *
     * @param  int $taskId Task ID to delete
     * @param  int $listId List ID to remove task from
     * @return void
     */
     public function deleteTask($taskId, $listId)
     {
        $postVars = array('id' => $taskId,
                          'list_id' => $listId,
                          'deleted' => 1);
        $rv       = $this->_curl(array(CURLOPT_URL  => $this->_wlUrl . '/ajax/tasks/update',
                                       CURLOPT_POST => 1,
                                       CURLOPT_POSTFIELDS => 'task=' . json_encode($postVars)));

        $data = json_decode($rv);
        if (is_object($data) === false) {
            throw new Exception('WARNING: Issue decoding data from change Task Priority request; task MAY NOT have been added');
        }

        if (isset($data->status) === false) {
            throw new Exception('WARNING: Status field not present in change Task Priority response; task MAY NOT have been added');
        }

        if ($data->status !== 'success') {
            throw new Exception('Error deleting task, status=[' . $data->status . ']');
        }
     }
}   // end class Wunderlist
