<?php
class Authwebserver extends AuthPluginBase
{
    protected $storage = 'DbStorage';    
    
    static protected $description = 'Core: Webserver authentication';
    static protected $name = 'Webserver';
    
    protected $settings = array(
        'strip_domain' => array(
            'type' => 'checkbox',
            'label' => 'Strip domain part (DOMAIN\\USER or USER@DOMAIN)'
        ),
        'serverkey' => array(
            'type' => 'string',
            'label' => 'Key to use for username e.g. PHP_AUTH_USER, LOGON_USER, REMOTE_USER. See phpinfo in global settings.',
            'default' => 'REMOTE_USER'
        ),
    );
    
    public function __construct(PluginManager $manager, $id) {
        parent::__construct($manager, $id);
        
        /**
         * Here you should handle subscribing to the events your plugin will handle
         */
        $this->subscribe('beforeLogin');
        $this->subscribe('newUserSession');
    }

    public function beforeLogin()
    {       
        // normal login through webserver authentication    
        $serverKey = $this->get('serverkey');
        if (!empty($serverKey) && isset($_SERVER[$serverKey]))
        {
            $sUser=$_SERVER[$serverKey];
            
            // Only strip domain part when desired
            if ($this->get('strip_domain', null, null, false)) {
                if (strpos($sUser,"\\")!==false) {
                    // Get username for DOMAIN\USER
                    $sUser = substr($sUser, strrpos($sUser, "\\")+1);
                } elseif (strpos($sUser,"@")!==false) {
                    // Get username for USER@DOMAIN
                    $sUser = substr($sUser, 0, strrpos($sUser, "@"));
                }
            }
            
            $aUserMappings=$this->api->getConfigKey('auth_webserver_user_map', array());
            if (isset($aUserMappings[$sUser])) 
            {
               $sUser = $aUserMappings[$sUser];
            }
            $this->setUsername($sUser);
            $this->setAuthPlugin(); // This plugin handles authentication, halt further execution of auth plugins
        }
    }
    
    public function newUserSession()
    {
        /* @var $identity LSUserIdentity */
        $sUser = $this->getUserName();
        
        $oUser = $this->api->getUserByName($sUser);
        if (is_null($oUser))
        {
            if (function_exists("hook_get_auth_webserver_profile"))
            {
                // If defined this function returns an array
                // describing the default profile for this user
                $aUserProfile = hook_get_auth_webserver_profile($sUser);
            }
            elseif ($this->api->getConfigKey('auth_webserver_autocreate_user'))
            {
                $aUserProfile=$this->api->getConfigKey('auth_webserver_autocreate_profile');
            }
        } else {
            $this->setAuthSuccess($oUser);
            return;
        }

        if ($this->api->getConfigKey('auth_webserver_autocreate_user') && isset($aUserProfile) && is_null($oUser))
        { // user doesn't exist but auto-create user is set
            $oUser=new User;
            $oUser->users_name=$sUser;
            $oUser->password=hash('sha256', createPassword());
            $oUser->full_name=$aUserProfile['full_name'];
            $oUser->parent_id=1;
            $oUser->lang=$aUserProfile['lang'];
            $oUser->email=$aUserProfile['email'];

            if ($oUser->save())
            {
                $permission=new Permission;
                $permission->setPermissions($oUser->uid, 0, 'global', $this->api->getConfigKey('auth_webserver_autocreate_permissions'), true);

                // read again user from newly created entry
                $this->setAuthSuccess($oUser);
                return;
            }
            else
            {
                $this->setAuthFailure(self::ERROR_USERNAME_INVALID);
            }

        }
        
    }  
    
    
}