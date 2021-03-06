<?php if (!defined('BASEPATH')) exit('No direct script access allowed');
/**
 * "The contents of this file are subject to the Mozilla Public License
 *  Version 1.1 (the "License"); you may not use this file except in
 *  compliance with the License. You may obtain a copy of the License at
 *  http://www.mozilla.org/MPL/

 *  Software distributed under the License is distributed on an "AS IS"
 *  basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See the
 *  License for the specific language governing rights and limitations
 *  under the License.

 *  The Original Code is OpenVBX, released June 15, 2010.

 *  The Initial Developer of the Original Code is Twilio Inc.
 *  Portions created by Twilio Inc. are Copyright (C) 2010.
 *  All Rights Reserved.

 * Contributor(s):
 **/

require_once(APPPATH.'libraries/twilio.php');

class SiteException extends Exception {}

class Site extends User_Controller
{
	const MODE_MULTI = 1;
	const MODE_SINGLE = 2;

	function __construct()
	{
		parent::__construct();
		$this->load->model('vbx_theme');
		$this->section = 'site settings';
		$this->admin_only($this->section);
	}

	public function index($action = '', $id = false)
	{
		return $this->site($action, $id);
	}

	private function site($action, $id)
	{
		$this->section = 'settings/site';

		switch($action)
		{
			case 'tenant':
				return $this->tenant_handler($id);
			default:
				return $this->site_handler();
		}
	}

	private function site_handler()
	{
		switch($this->request_method)
		{
			case 'POST':
				return $this->update_site();
			default:
				return $this->get_site();
		}
	}

	private function tenant_handler($id)
	{
		switch($this->request_method)
		{
			case 'POST':
				if($id)
				{
					return $this->update_tenant($id);
				}
				return $this->add_tenant();
			case 'GET':
				if($id)
				{
					return $this->get_tenant($id);
				}
			default:
				return redirect('settings/site');
		}
	}

	private function get_current_settings($id = false)
	{
		if(!$id)
		{
			$id = $this->tenant->id;
		}

		$current_settings = $this->settings->get_all_by_tenant_id($id);

		$sorted_settings = array();
		foreach($current_settings as $setting)
		{
			$sorted_settings[$setting->name] = array(
													 'id' => $setting->id,
													 'value' => $setting->value
													 );
		}

		return $sorted_settings;
	}

	private function get_site()
	{
		$this->template->add_js('assets/j/settings.js');

		$data = $this->init_view_data();
		$current_settings = $this->get_current_settings();
		$data = array_merge($data, $current_settings);
		$data['tenant_mode'] = self::MODE_SINGLE;
		if($this->tenant->name == 'default')
		{
			$data['tenant_mode'] = self::MODE_MULTI;
			$data['tenants'] = $this->settings->get_all_tenants();
		}
		else {
			// allow tenants to see the rewrite setting
			$data['rewrite_enabled'] = array(
				'value' => intval($this->settings->get('rewrite_enabled', VBX_PARENT_TENANT))
			);
		}

		if ($this->db->driver == 'mysqli')
		{
			$mysql_version = $this->db->conn_id->server_info;
		}
		else {
			$mysql_version = mysql_get_server_info();
		}

		$data['server_info'] = array(
			'php_version' => phpversion(),
			'mysql_version' => $mysql_version,
			'mysql_driver' => $this->db->dbdriver,
			'apache_version' => $_SERVER['SERVER_SOFTWARE']
		);

		$data['available_themes'] = $this->get_available_themes();
		
		$plugins = Plugin::all();
		foreach($plugins as $plugin)
		{
			$data['plugins'][] = $plugin->getInfo();
		}
		$data['error'] = $this->session->flashdata('error');

		$data['json']['settings'] = $current_settings;

		$this->load->model('vbx_incoming_numbers');
		$data['countries'] = array();
		if ($countrydata = $this->vbx_incoming_numbers->get_available_countries())
		{
			foreach ($countrydata as $country)
			{
				$data['countries'][$country->country_code] = $country->country;
			}
		}

		$this->respond('Site Settings', 'settings/site', $data);
	}

	private function update_site()
	{
		$data = array('message' => '', 'error' => false);
		$site = $this->input->post('site');

		$current_app_sid = $this->settings->get('application_sid', $this->tenant->id);

		if(!empty($site))
		{
			try {
				foreach($site as $name => $value)
				{
					if ($name == 'application_sid')
					{
						$app_sid = $value;
					}
					if ($name == 'connect_application_sid') {
						$connect_app_sid = $value;
					}
					$this->settings->set($name, trim($value), $this->tenant->id);
				}

				// Connect App (if applicable)
				if (!empty($connect_app_sid) && $this->tenant->id == VBX_PARENT_TENANT) {
					$account = OpenVBX::getAccount();
					$connect_app = $account->connect_apps->get($connect_app_sid);
					
					$required_settings = array(
						'HomepageUrl' => site_url(),
						'AuthorizeRedirectUrl' => site_url('/auth/connect'),
						'DeauthorizeCallbackUrl' => site_url('/auth/connect/deauthorize'),
						'Permissions' => array(
							'get-all',
							'post-all'
						)
					);
					
					$updated = false;
					foreach ($required_settings as $key => $setting) {
						$app_key = Services_Twilio::decamelize($key);
						if ($connect_app->$app_key != $setting) {
							$connect_app->$app_key = $setting;
							$updated = true;
						}
					}
					
					if ($updated) {
						$connect_app->update(array(
							'FriendlyName' => $connect_app->friendly_name,
							'Description' => $connect_app->description,
							'CompanyName' => $connect_app->company_name,
							'HomepageUrl' => $required_settings['HomepageUrl'],
							'AuthorizeRedirectUrl' => $required_settings['AuthorizeRedirectUrl'],
							'DeauthorizeCallbackUrl' => $required_settings['DeauthorizeCallbackUrl'],
							'Permissions' => implode(',', $required_settings['Permissions'])
						));
					}
				}
				
				// Client App
				$update_app = false;
				if (empty($app_sid) && !empty($current_app_sid))
				{
					// disassociate the current app from this install
					$update_app[] = array(
									  'app_sid' => $current_app_sid,
									  'params' => array(
													'VoiceUrl' => '',
													'VoiceFallbackUrl' => '',
													'SmsUrl' => '',
													'SmsFallbackUrl' => ''
												)
									  );
				}
				elseif (!empty($app_sid))
				{
					// update the application data
					$update_app[] = array(
									  'app_sid' => $app_sid,
									  'params' => array(
													'VoiceUrl' => site_url('/twiml/dial'),
													'VoiceFallbackUrl' => site_url('/fallback/voice.php'),
													'VoiceMethod' => 'POST',
													'SmsUrl' => '',
													'SmsFallbackUrl' => '',
													'SmsMethod' => 'POST'
												)
									  );

					if ($app_sid != $current_app_sid) 
					{
						// app sid changed, disassociate the old app from this install
						$update_app[] = array(
										  'app_sid' => $current_app_sid,
										  'params' => array(
														'VoiceUrl' => '',
														'VoiceFallbackUrl' => '',
														'SmsUrl' => '',
														'SmsFallbackUrl' => ''
													)
										  );
					}
				}

				if (!empty($update_app))
				{
					if (empty($account)) {
						$account = OpenVBX::getAccount();
					}

					foreach ($update_app as $app) 
					{
						try {
							$application = $account->applications->get($app['app_sid']);
							$application->update(array_merge($app['params'], array('FriendlyName' => $application->friendly_name)));
						}
						catch (Exception $e) {
							$this->session->set_flashdata('error', 'Could not update Application: '.$e->getMessage());
							throw new SiteException($e->getMessage());
						}
					}					
				}

				$this->session->set_flashdata('error', 'Settings have been saved');
			}
			catch(SiteException $e) {
				$data['error'] = true;
				switch($e->getCode()) {
					case '0':
						$data['message'] = $message = 'Could not Authenticate with Twilio. Please check your Sid & Token values.';
						break;
					default:
						$data['message'] = $message = $e->getMessage();
				}

				$this->session->set_flashdata('error', $message);
			}
		}
		
		flush_minify_caches();

		if($this->response_type == 'html')
		{
			redirect('settings/site');
		}

		$this->respond('', 'settings/site', $data);
	}

	private function create_application_for_subaccount($tenant_id, $name, $accountSid) 
	{
		$appName = "OpenVBX - {$name}";
		
		$application = false;
		try {
			$accounts = OpenVBX::getAccounts();
			$sub_account = $accounts->get($accountSid);
			foreach ($sub_account->applications as $_application) 
			{
				if ($application->friendly_name == $appName) 
				{
					$application = $_application;
				}
			}
		}
		catch (Exception $e) {
			throw new VBX_SettingsException($e->getMessage());
		}

		$params = array(
				'FriendlyName' => $appName,
				'VoiceUrl' => tenant_url('twiml/dial', $tenant_id),
				'VoiceFallbackUrl' => asset_url('fallback/voice.php'),
				'VoiceMethod' => 'POST',
				'SmsUrl' => '',
				'SmsFallbackUrl' => '',
				'SmsMethod' => 'POST'
			);

		try {
			if (!empty($application)) 
			{
				$application->update($params);
			}
			else 
			{
				$application = $sub_account->applications->create($appName, $params);
			}
		}
		catch (Exception $e) {
			throw new VBX_SettingsException($e->getMessage());
		}

		return $application->sid;
	}

	private function add_tenant()
	{
		$tenant = $this->input->post('tenant');
		
		if (empty($tenant['url_prefix']))
		{
			$data['error'] = true;
			$data['message'] = 'A valid tenant name is required';
			$this->session->set_flashdata('error', 'Failed to add new tenant: '.$data['message']);
		}
		if (empty($tenant['admin_email']) || 
			!filter_var($tenant['admin_email'], FILTER_VALIDATE_EMAIL))
		{
			$data['error'] = true;
			$data['message'] = 'A valid admin email address is required';
			$this->session->set_flashdata('error', 'Failed to add new tenant: '.$data['message']);
		}
		
		if(!empty($tenant) && empty($data['error']))
		{
			try {
				$data['id'] = $this->settings->tenant($tenant['url_prefix'],
													  urlencode($tenant['url_prefix']),
													  '');

				$this->db->trans_start();
				$user = new VBX_User();
				$user->fields[] = 'tenant_id'; // monkey patching to override tenant_id
				$user->first_name = '';
				$user->last_name = '';
				$user->password = '';
				$user->values['tenant_id'] = $data['id']; // hidden field not in ORM
				$user->email = $tenant['admin_email'];
				$user->is_active = TRUE;
				$user->is_admin = TRUE;
				$user->auth_type = 1;
				try {
					$user->save();
				}
				catch(VBX_UserException $e) {
					throw new VBX_SettingsException($e->getMessage());
				}


				foreach($this->settings->setting_options as $param)
				{
					$this->settings->add($param, '', $data['id']);
				}

				$this->settings->set('from_email', $tenant['admin_email'], $data['id']);
				$friendlyName = substr($tenant['url_prefix'].' - '.$tenant['admin_email'], 0, 32);					

				switch ($this->input->post('auth_type')) 
				{
					case 'connect':
						$auth_type = VBX_Settings::AUTH_TYPE_CONNECT;
						break;
					case 'subaccount':
					default:
						$auth_type = VBX_Settings::AUTH_TYPE_SUBACCOUNT;
						break;
				}

				/**
				 * Only do app setup for sub-accounts.
				 * Connect tenants will get set up after going through the connect process.
				 */
				if ($auth_type === VBX_Settings::AUTH_TYPE_SUBACCOUNT) 
				{
					try {
						$accounts = OpenVBX::getAccounts();

						// default, sub-account
						$sub_account = $accounts->create(array(
															'FriendlyName' => $friendlyName
														));

						$tenant_sid = $sub_account->sid;
						$tenant_token = $sub_account->auth_token;
						$this->settings->add('twilio_sid', $tenant_sid, $data['id']);
						$this->settings->add('twilio_token', $tenant_token, $data['id']);
						
						$app_sid = $this->create_application_for_subaccount($data['id'], $tenant['url_prefix'], $tenant_sid);
						$this->settings->add('application_sid', $app_sid, $data['id']);
					}
					catch (Exception $e) {
						throw new VBX_SettingsException($e->getMessage());
					}
				}
				elseif ($auth_type === VBX_Settings::AUTH_TYPE_CONNECT)
				{
					// when using connect, we won't get a sid, token, or 
					// app_sid until user first login
					$tenant_id = $tenant_token = $app_sid = null;
				}
				else 
				{
					throw new VBX_SettingsException('Unknown auth-type encountered during '.
													'tenant creation');
				}

				$this->settings->update_tenant(array(
					'id' => $data['id'],
					'type' => $auth_type
				));
				
				$tenant_defaults = array(
					'transcriptions' => 1,
					'voice' => 'man',
					'voice_language' => 'en',
					'numbers_country' => 'US',
					'gravatars' => 0
				);
				foreach ($tenant_defaults as $key => $value) {
					$this->settings->set($key, $value, $data['id']);
				}
				$this->settings->add('tenant_first_run', 1, $data['id']);
				
				$this->db->trans_complete();
				$this->session->set_flashdata('error', 'Added new tenant');
				$user->send_new_user_notification();

				if(isset($data['id']))
				{
					return redirect('settings/site/tenant/'.$data['id']);
				}
			}
			catch(VBX_SettingsException $e) {
				error_log($e->getMessage());
				$this->db->trans_rollback();
				// TODO: rollback in twilio.
				$this->session->set_flashdata('error', 'Failed to add new tenant: '.$e->getMessage());
				$data['error'] = true;
				$data['message'] = $e->getMessage();
			}
		}

		if($this->response_type == 'html')
		{
			redirect('settings/site');
		}

		$this->respond('', 'settings/site', $data);
	}

	public function get_tenant($id)
	{
		$data = $this->init_view_data();
		$tenant = $this->settings->get_tenant_by_id($id);
		$tenant_settings = $this->get_current_settings($id);

		$data['tenant'] = $tenant;
		$data['tenant_settings'] = $tenant_settings;		
		$data['available_themes'] = $this->get_available_themes();
		$data['rewrite_enabled'] = array(
			'value' => intval($this->settings->get('rewrite_enabled', VBX_PARENT_TENANT))
		);
		
		$this->respond('Tenant Settings', 'settings/tenant', $data);
	}

	private function update_tenant($id)
	{
		$tenant = $this->input->post('tenant');
		$tenant_settings = $this->input->post('tenant_settings');

		$tenant['id'] = $id;
		try
		{
			$this->settings->update_tenant($tenant);
			foreach($this->settings->setting_options as $param)
			{
				if(isset($tenant_settings[$param]))
				{
					$this->settings->set($param, trim($tenant_settings[$param]), $id);
				}
			}
		}
		catch(SettingsException $e)
		{
			$this->session->set_flashdata('error', $e->getMessage());
			$data['error'] = true;
			$data['message'] = $e->getMessage();
		}

		if($this->response_type == 'html')
		{
			redirect('settings/site/tenant/'.$id);
		}

		$this->respond('', 'settings/tenant', $data);
	}

	private function get_available_themes() {
		$available_themes = array();
		$all_themes = $this->vbx_theme->get_all();
		foreach ($all_themes as $theme) {
			$available_themes[$theme] = ucwords($theme);
		}
		return $available_themes;
	}
}
