<?php

require_once 'library/CE/NE_MailGateway.php';
require_once 'modules/admin/models/ServerPlugin.php';

/**
* @package Plugins
*/
class PluginDirectAdmin extends ServerPlugin
{
    public $features = array(
        'packageName' =>  true,
        'testConnection' => true,
        'showNameservers' => true,
        'directlink' => true,
        'upgrades' => true
    );

    public function getVariables()
    {
        $variables = [
            lang('Name') => [
                'type' => 'hidden',
                'description' => 'Used By CE to show plugin - must match how you call the action function names',
                'value' => 'DirectAdmin'
            ],
            lang('Description') => [
                'type' => 'hidden',
                'description' => lang('Description viewable by admin in server settings'),
                'value' => lang('DirectAdmin control panel integration')
            ],
            lang('Username') => [
                'type' => 'text',
                'description' => lang('Username used to connect to server'),
                'value' => ''
            ],
            lang('Password') => [
                'type' => 'password',
                'description' => lang('Password used to connect to server'),
                'value' => '',
                'encryptable'=> true
            ],
            lang('Failure E-mail') => [
                'type' => 'text',
                'description' => lang('An email will be sent to this email address in case of a failure'),
                'value' => ''
            ],
            lang('Use SSL') => [
                'type' => 'yesno',
                'description'=> '',
                'value' => '1'
            ],
            lang('Port') => [
                'type' => 'text',
                'description' => lang('Port used to connect to server'),
                'value' => '2222'
            ],
            lang('reseller') => [
                'type' => 'hidden',
                'description' => lang('Whether this server plugin can set reseller accounts'),
                'value' => '1',
            ],
            lang('Actions') => [
                'type' => 'hidden',
                'description' => lang('Current actions that are active for this plugin per server'),
                'value' => 'Create,Delete,Update,Suspend,UnSuspend'
            ]
        ];
        return $variables;
    }

    public function validateCredentials($args)
    {
        // direct admin only allows for all lowercase usernames.
        $args['package']['username'] = trim(strtolower($args['package']['username']));
        return $args['package']['username'];
    }

    private function processResult($result)
    {
        $return = [];
        if (substr($result, 0, 7) == 'error=1') {
            $msg = explode('&', $result);
            $return['error'] = '1';
            $return['msg'] = $msg[1];
        } else {
            $return['error'] = '0';
            $result = explode('&', $result);
            if (isset($result[2])) {
                $return['details'] = urldecode(substr($result[2], 8));
            }
        }
        return $return;
    }

    public function create($args)
    {
        $errormsg = "";
        $packageName = $args['package']['name_on_server'];

        if (isset($args['package']['is_reseller']) && $args['package']['is_reseller'] == 1) {
            $cmd = '/CMD_API_ACCOUNT_RESELLER';
            $ip = 'shared';
        } else {
            $cmd = '/CMD_API_ACCOUNT_USER';
            $ip = $args['package']['ip'];
        }
        $sock = new DA($args);
        $sock->setMethod('POST');
        $tArray = [
            'action' => 'create',
            'add' => 'Submit',
            'username' => strtolower($args['package']['username']),
            'email' => $args['customer']['email'],
            'passwd' => $args['package']['password'],
            'passwd2' => $args['package']['password'],
            'domain' => strtolower($args['package']['domain_name']),
            'package' => $packageName,
            'ip' => $ip,
            'notify' => 'no'
        ];

        $result = $sock->query($cmd, $tArray);

        CE_Lib::log(4, 'DirectAdmin Create Result: ' . $result);
        $result = $this->processResult($result);

        if ($result['error'] == '1') {
            $mailGateway = new NE_MailGateway();

            $mailGateway->mailMessageEmail(
                "DirectAdmin plugin: A failure occurred while connecting to the DA server. Message Returned: {$result['msg']}.",
                $args['server']['variables']['plugin_directadmin_Failure_E-mail'],
                "DirectAdmin Plugin",
                $args['server']['variables']['plugin_directadmin_Failure_E-mail'],
                "",
                "[CE] DirectAdmin plugin: Connection to DA server failed"
            );

            $errormsg = "A failure occurred while connecting to the DA server. An E-mail with details has been sent to ".$args['server']['variables']['plugin_directadmin_Failure_E-mail'].". Please note that your query has not been executed on the server.";
            CE_Lib::log(4, 'DirectAdmin Create Error: ' . $result['msg']);

            throw new CE_Exception($errormsg);
        }
        return;
    }

    public function delete($args)
    {
        $sock = new DA($args);
        $sock->setMethod('POST');
        $tArray = [
            'confirmed' => 'Confirm',
            'delete' => 'yes',
            'select0' => strtolower($args['package']['username'])
        ];
        $result = $sock->query('/CMD_API_SELECT_USERS', $tArray);
        CE_Lib::log(4, 'DirectAdmin Delete Result: ' . $result);
        $result = $this->processResult($result);

        if ($result['error'] == '1') {
            $mailGateway = new NE_MailGateway();

            $mailGateway->mailMessageEmail(
                "DirectAdmin plugin: A failure occurred while deleting ".$args['package']['username'].".  Message Returned: {$result['msg']}.",
                $args['server']['variables']['plugin_directadmin_Failure_E-mail'],
                "DirectAdmin Plugin",
                $args['server']['variables']['plugin_directadmin_Failure_E-mail'],
                "",
                "[CE] DirectAdmin plugin: Failure on deleting user."
            );

            $errormsg = "A failure occurred while deleting a user. An E-mail with details has been sent to ".$args['server']['variables']['plugin_directadmin_Failure_E-mail'].".";
            CE_Lib::log(4, 'DirectAdmin Delete Error: ' . $errormsg);

            throw new CE_Exception($errormsg);
        }
    }

    public function update($args)
    {
        $errormsg = "";
        $packagename = $args['package']['name_on_server'];

        $sock = new DA($args);
        $sock->setMethod('POST');

        foreach ($args['changes'] as $key => $value) {
            $mailGateway = new NE_MailGateway();

            switch ($key) {
                case 'password':
                    $tArray = [
                        'username' => strtolower($args['package']['username']),
                        'passwd' => $value,
                        'passwd2' => $value
                    ];
                    $result = $sock->query('/CMD_API_USER_PASSWD', $tArray);
                    CE_Lib::log(4, 'DirectAdmin Password Update Result: ' . $result);
                    $result = $this->processResult($result);

                    if ($result['error'] == '1') {
                        $mailGateway->mailMessageEmail(
                            "DirectAdmin plugin: A failure occurred while changing the password of ".$args['package']['username'].".  Message Returned: {$result['msg']}.",
                            $args['server']['variables']['plugin_directadmin_Failure_E-mail'],
                            "DirectAdmin Plugin",
                            $args['server']['variables']['plugin_directadmin_Failure_E-mail'],
                            "",
                            "[CE] DirectAdmin plugin: Failure on changing password"
                        );

                        // Create and log the error. Then throw an error.
                        $errormsg = "A failure occurred while changing the password. An E-mail with details has been sent to ".$args['server']['variables']['plugin_directadmin_Failure_E-mail'].".";
                        CE_Lib::log(4, 'DirectAdmin Password Update Error: ' . $errormsg);

                        throw new CE_Exception($errormsg);
                    }
                    break;

                case 'ip':
                    $tArray = [
                        'action' => 'ip',
                        'user' => strtolower($args['package']['username']),
                        'ip' => $value
                    ];

                    $result = $sock->query('/CMD_MODIFY_USER', $tArray);
                    CE_Lib::log(4, 'DirectAdmin IP Update Result: ' . $result);
                    if ($result['error'] == '1') {
                        $mailGateway->mailMessageEmail(
                            "DirectAdmin plugin: A failure occurred while changing the IP of user ".$args['package']['username'].". Message Returned: {$result['msg']}.",
                            $args['server']['variables']['plugin_directadmin_Failure_E-mail'],
                            "DirectAdmin Plugin",
                            $args['server']['variables']['plugin_directadmin_Failure_E-mail'],
                            "",
                            "[CE] DirectAdmin plugin: Failure on changing IP"
                        );

                        // Create and log the error. Then throw an error.
                        $errormsg = "A failure occurred while changing the IP. An E-mail with details has been sent to ".$args['server']['variables']['plugin_directadmin_Failure_E-mail'].".";
                        CE_Lib::log(4, 'DirectAdmin IP Update Error: ' . $errormsg);

                        throw new CE_Exception($errormsg);
                    }
                    break;

                case 'package':
                    $tArray = [
                        'action' => 'package',
                        'user' => strtolower($args['package']['username']),
                        'package' => $value
                    ];

                    $result = $sock->query('/CMD_MODIFY_USER', $tArray);
                    CE_Lib::log(4, 'DirectAdmin Package Update Result: ' . $result);

                    if ($result['error'] == '1') {
                        $mailGateway->mailMessageEmail(
                            "DirectAdmin plugin: A failure occurred while changing the package of user ".$args['package']['username'].". Message Returned: {$result['msg']}.",
                            $args['server']['variables']['plugin_directadmin_Failure_E-mail'],
                            "DirectAdmin Plugin",
                            $args['server']['variables']['plugin_directadmin_Failure_E-mail'],
                            "",
                            "[CE] DirectAdmin plugin: Failure on changing package"
                        );

                        // Create and log the error. Then throw an error.
                        $errormsg = "A failure occurred while changing the package. An E-mail with details has been sent to ".$args['server']['variables']['plugin_directadmin_Failure_E-mail'].".";
                        CE_Lib::log(4, 'DirectAdmin Package Update Error: ' . $errormsg);

                        throw new CE_Exception($errormsg);
                    }
                    break;
            }
        }
        return;
    }

    public function suspend($args)
    {
        $sock = new DA($args);
        $sock->setMethod('POST');
        $tArray = [
            'location' => 'CMD_SELECT_USERS',
            'suspend'  => 'Suspend/Unsuspend',
            'select0'  => strtolower($args['package']['username']),
            'dosuspend' => 1
        ];
        $result = $sock->query('/CMD_API_SELECT_USERS', $tArray);

        CE_Lib::log(4, 'DirectAdmin Suspend Result: ' . $result);
        $result = $this->processResult($result);

        if ($result['error'] == '1') {
            $mailGateway = new NE_MailGateway();

            $mailGateway->mailMessageEmail(
                "DirectAdmin plugin: A failure occurred while suspending ".$args['package']['username'].".  Message Returned: {$result['msg']}.",
                $args['server']['variables']['plugin_directadmin_Failure_E-mail'],
                "DirectAdmin Plugin",
                $args['server']['variables']['plugin_directadmin_Failure_E-mail'],
                "",
                "[CE] DirectAdmin plugin: Failure on suspending user."
            );

           // Create and log the error. Then throw an error.
            $errormsg = "A failure occurred while suspending a user. An E-mail with details has been sent to ".$args['server']['variables']['plugin_directadmin_Failure_E-mail'].".";
            CE_Lib::log(4, 'DirectAdmin Suspend Error: ' . $errormsg);

            throw new CE_Exception($errormsg);
        }
    }

    public function unsuspend($args)
    {
        $sock = new DA($args);
        $sock->setMethod('POST');
        $tArray = [
            'location' => 'CMD_SELECT_USERS',
            'suspend'  => 'Suspend/Unsuspend',
            'select0'  => strtolower($args['package']['username']),
            'dounsuspend' => 1
        ];
        $result = $sock->query('/CMD_API_SELECT_USERS', $tArray);

        CE_Lib::log(4, 'DirectAdmin UnSuspend Result: ' . $result);
        $result = $this->processResult($result);

        if ($result['error'] == '1') {
            $mailGateway = new NE_MailGateway();

            $mailGateway->mailMessageEmail(
                "DirectAdmin plugin: A failure occurred while unsuspending ".$args['package']['username'].".  Message Returned: {$result['msg']}.",
                $args['server']['variables']['plugin_directadmin_Failure_E-mail'],
                "DirectAdmin Plugin",
                $args['server']['variables']['plugin_directadmin_Failure_E-mail'],
                "",
                "[CE] DirectAdmin plugin: Failure on suspending user."
            );

            $errormsg = "A failure occurred while unsuspending a user. An E-mail with details has been sent to ".$args['server']['variables']['plugin_directadmin_Failure_E-mail'].".";
            CE_Lib::log(4, 'DirectAdmin UnSuspend Error: ' . $errormsg);

            throw new CE_Exception($errormsg);
        }
    }

    public function doCreate($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->create($this->buildParams($userPackage));
        return $userPackage->getCustomField("Domain Name") .  ' has been created.';
    }

    public function doUpdate($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->update($this->buildParams($userPackage, $args));
        return $userPackage->getCustomField("Domain Name") .  ' has been update.';
    }

    public function doSuspend($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->suspend($this->buildParams($userPackage));
        return $userPackage->getCustomField("Domain Name") .  ' has been suspended.';
    }

    public function doUnSuspend($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->unsuspend($this->buildParams($userPackage));
        return $userPackage->getCustomField("Domain Name") .  ' has been unsuspended.';
    }

    public function doDelete($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->delete($this->buildParams($userPackage));
        return $userPackage->getCustomField("Domain Name") . ' has been deleted.';
    }

    public function doCheckUserName($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        return $this->checkUserName($this->buildParams($userPackage));
    }

    public function checkUserName($args)
    {
        $sock = new DA($args);
        $sock->setMethod('GET');
        $str = 'user=' .  strtolower($args['package']['username']);
        $result = $sock->query('/CMD_API_SHOW_USER_CONFIG?' . $str);
        $result = $this->processResult($result);
        if ($result['error'] == '1') {
            return false;
        } else {
            return true;
        }
    }

    public function getAvailableActions($userPackage)
    {
        $args = $this->buildParams($userPackage);
        $actions = [];
        $sock = new DA($args);
        $sock->setMethod('GET');
        $str = 'user=' .  strtolower($args['package']['username']);
        $result = $sock->query('/CMD_API_SHOW_USER_CONFIG?' . $str);
        if (substr($result, 0, 7) == 'error=1') {
            $actions[] = 'Create';
        } else {
            $info = explode('&', $result);
            $suspended = '';
            foreach ($info as $i) {
                $tmp = explode('=', $i);
                if ($tmp[0] == 'suspended') {
                    $suspended = $tmp[1];
                    break;
                }
            }
            if ($suspended == 'yes') {
                $actions[] = 'UnSuspend';
            } else {
                $actions[] = 'Suspend';
            }

            $actions[] = 'Delete';
        }
        return $actions;
    }

    public function testConnection($args)
    {
        CE_Lib::log(4, 'Testing connection to DirectAdmin Server');
        $sock = new DA($args);
        $sock->setMethod('GET');
        $result = $sock->query('/CMD_API_SHOW_USERS');
        if (strpos($result, "CMD_LOGIN") == true) {
            throw new CE_Exception('Connection to server failed');
        }
    }

    function getDirectLink($userPackage, $getRealLink = true, $fromAdmin = false, $isReseller = false)
    {
        $args = $this->buildParams($userPackage);
        $linkText = $this->user->lang('Login to DirectAdmin');
        if ($fromAdmin) {
            $cmd = 'panellogin';
            return [
                'cmd' => $cmd,
                'label' => $linkText
            ];
        } elseif ($getRealLink) {
            $sock = new DA($args);
            $sock->setMethod('POST');
            $sock->loginAs(strtolower($args['package']['username']));
            $result = $sock->query(
                '/CMD_API_LOGIN_KEYS',
                [
                    'max_uses' => 1,
                    'clear_key' => 'no',
                    'action' => 'create',
                    'type' => 'one_time_url',
                    'passwd' => $args['server']['variables']['plugin_directadmin_Password'],
                ]
            );
            $result = $this->processResult($result);
            if ($result['details'] != '') {
                return [
                    'link'    => '<li><a target="_blank" href="' . $result['details'] .'">' .$linkText . '</a></li>',
                    'rawlink' =>  $result['details'],
                    'form'    => ''
                ];
            } else {
                throw new CE_Exception('No URL, check that login keys are enabled');
            }
        } else {
            $link = 'index.php?fuse=clients&controller=products&action=openpackagedirectlink&packageId='.$userPackage->getId().'&sessionHash='.CE_Lib::getSessionHash();

            return [
                'link' => '<li><a target="_blank" href="' . $link .  '">' .$linkText . '</a></li>',
                'form' => ''
            ];
        }
    }


    function dopanellogin($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $response = $this->getDirectLink($userPackage);
        return $response['rawlink'];
    }
}

class DA
{
    var $method = 'GET';
    var $host;
    var $port;
    var $user;
    var $pass;
    var $useSSL;
    var $settings;

    public function __construct($args)
    {
        if (substr($args['server']['variables']['ServerHostName'], 0, 6) == 'ssl://') {
            $this->host = substr($args['server']['variables']['ServerHostName'], 6);
        } else {
            $this->host = $args['server']['variables']['ServerHostName'];
        }
        $this->port = $args['server']['variables']['plugin_directadmin_Port'];
        $this->useSSL = $args['server']['variables']['plugin_directadmin_Use_SSL'];
        $this->user = $args['server']['variables']['plugin_directadmin_Username'];
        $this->pass = $args['server']['variables']['plugin_directadmin_Password'];
    }

    public function loginAs($login)
    {
        $this->user = $this->user . '|' . $login;
    }

    public function setMethod($method = 'GET')
    {
        $this->method = strtoupper($method);
    }

    public function query($request, $content = '')
    {
        $url = ($this->useSSL) ? 'https://' : 'http://';
        $url .=  "{$this->host}:{$this->port}{$request}";
        $ch = curl_init($url);

        $caPathOrFile = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        if (is_dir($caPathOrFile)) {
            curl_setopt($ch, CURLOPT_CAPATH, $caPathOrFile);
        } else {
            curl_setopt($ch, CURLOPT_CAINFO, $caPathOrFile);
        }
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->method);

        if ($this->method == 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        }

        curl_setopt($ch, CURLOPT_USERPWD, $this->user . ":" . $this->pass);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $this->result = curl_exec($ch);
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new CE_Exception('DirectAdmin Error: ' . $error);
        }
        curl_close($ch);
        return $this->result;
    }
}
