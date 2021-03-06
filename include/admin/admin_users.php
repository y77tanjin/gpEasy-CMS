<?php
defined('is_running') or die('Not an entry point...');



class admin_users{

	var $users;
	var $possible_permissions	= array();
	var $has_weak_pass			= false;


	function __construct(){
		global $page,$langmessage;

		$page->head_js[] = '/include/js/admin_users.js';

		//set possible_permissions
		$scripts = admin_tools::AdminScripts();
		foreach($scripts as $script => $info){
			$this->possible_permissions[$script] = $info['label'];
		}


		$this->GetUsers();
		$cmd = common::GetCommand();
		switch($cmd){

			case 'save_file_permissions':
				if( $this->SaveFilePermissions() ){
					return;
				}
			case 'file_permissions':
				$this->FilePermissions();
			return;

			case 'newuser':
				if( $this->CreateNewUser() ){
					break;
				}
			case 'newuserform';
				$this->NewUserForm();
			return;

			case 'rm':
				$this->RmUserConfirmed();
			break;

			case 'resetpass':
				if( $this->ResetPass() ){
					break;
				}
			case 'changepass':
				$this->ChangePass();
			return;


			case 'SaveChanges':
				if( $this->SaveChanges() ){
					break;
				}
			case 'details':
				$this->ChangeDetails();
			return;

		}

		$this->ShowForm();
	}

	/**
	 * Save changes made to an existing user's permissions
	 *
	 */
	function SaveChanges(){
		global $langmessage, $dataDir,$gpAdmin;

		$username =& $_REQUEST['username'];
		if( !isset($this->users[$username]) ){
			message($langmessage['OOPS']);
			return false;
		}

		if( !empty($_POST['email']) ){
			$this->users[$username]['email'] = $_POST['email'];
		}

		$this->users[$username]['granted'] = $this->GetPostedPermissions($username);
		$this->users[$username]['editing'] = $this->GetEditingPermissions();

		//this needs to happen before SaveUserFile();
		//update the /_session file
		includeFile('tool/sessions.php');
		$userinfo =& $this->users[$username];
		$userinfo = gpsession::SetSessionFileName($userinfo,$username); //make sure $userinfo['file_name'] is set


		if( !$this->SaveUserFile() ){
			message($langmessage['OOPS']);
			return false;
		}

		// update the $user_file_name file
		$is_curr_user = ($gpAdmin['username'] == $username);
		$this->UserFileDetails($username,$is_curr_user);
		return true;
	}

	/**
	 * Update the users session file with new permission data
	 *
	 */
	function UserFileDetails($username,$is_curr_user){
		global $dataDir;

		$user_info			= $this->users[$username];
		$user_file			= $dataDir.'/data/_sessions/'.$user_info['file_name'];

		if( $is_curr_user ){
			global $gpAdmin;
		}else{
			$gpAdmin = gpFiles::Get($user_file,'gpAdmin');
		}

		if( !$gpAdmin ){
			return;
		}

		$gpAdmin['granted'] = $user_info['granted'];
		$gpAdmin['editing'] = $user_info['editing'];
		gpFiles::SaveData($user_file,'gpAdmin',$gpAdmin);
	}

	/**
	 * Display the permissions of a single user
	 *
	 */
	function ChangeDetails(){
		global $langmessage;

		$username =& $_REQUEST['username'];
		if( !isset($this->users[$username]) ){
			message($langmessage['OOPS']);
			return false;
		}

		$userinfo = $this->users[$username];

		echo '<form action="'.common::GetUrl('Admin_Users').'" method="post" id="permission_form">';
		echo '<input type="hidden" name="cmd" value="SaveChanges" />';
		echo '<input type="hidden" name="username" value="'.htmlspecialchars($username).'" />';

		echo '<table class="bordered">';
		echo '<tr>';
			echo '<th colspan="2">';
			echo $langmessage['details'];
			echo ' - ';
			echo $username;
			echo '</th>';
			echo '</tr>';

		$this->DetailsForm($userinfo,$username);

		echo '<tr><td>';
			echo '</td><td>';
			echo ' <input type="submit" name="aaa" value="'.$langmessage['save'].'" class="gpsubmit"/>';
			echo ' <input type="reset" class="gpsubmit" />';
			echo ' <input type="submit" name="cmd" value="'.$langmessage['cancel'].'" class="gpcancel"/>';
			echo '</td>';
			echo '</tr>';

		echo '</table>';
		echo '</form>';

	}

	/**
	 * Remove a user from the installation
	 *
	 */
	function RmUserConfirmed(){
		global $langmessage;
		$username = $this->CheckUser();

		if( $username == false ){
			return;
		}

		unset($this->users[$username]);
		return $this->SaveUserFile();
	}

	/**
	 * Make sure the submitted username exists
	 *
	 */
	function CheckUser(){
		global $langmessage,$gpAdmin;
		$username = $_POST['username'];

		if( !isset($this->users[$username]) ){
			message($langmessage['OOPS']);
			return false;
		}

		//don't allow deleting self
		if( $username == $gpAdmin['username'] ){
			message($langmessage['OOPS']);
			return false;
		}
		return $username;
	}



	function CreateNewUser(){
		global $langmessage;
		$_POST += array('grant'=>'');

		if( ($_POST['password']=="") || ($_POST['password'] !== $_POST['password1'])  ){
			message($langmessage['invalid_password']);
			return false;
		}


		$newname = $_POST['username'];
		$test = str_replace( array('.','_'), array(''), $newname );
		if( empty($test) || !ctype_alnum($test) ){
			message($langmessage['invalid_username']);
			return false;
		}

		if( isset($this->users[$newname]) ){
			message($langmessage['OOPS']);
			return false;
		}


		if( !empty($_POST['email']) ){
			$this->users[$newname]['email'] = $_POST['email'];
		}

		$this->users[$newname]['granted']	= $this->GetPostedPermissions($newname);
		$this->users[$newname]['editing']	= $this->GetEditingPermissions();

		self::SetUserPass( $this->users[$newname], $_POST['password']);

		return $this->SaveUserFile();
	}


	/**
	 * Set the user password and password hash algorithm
	 *
	 */
	static function SetUserPass( &$user_info, $password ){

		if( function_exists('password_hash') ){
			$temp					= common::hash($_POST['password'],'sha512',50);
			$user_info['password']	= password_hash($temp,PASSWORD_DEFAULT);
			$user_info['passhash']	= 'password_hash';

		}else{
			$user_info['password']	= common::hash($_POST['password'],'sha512');
			$user_info['passhash']	= 'sha512';
		}

	}


	/**
	 * Return the posted admin permissions
	 *
	 */
	function GetPostedPermissions($username){
		global $gpAdmin;

		if( isset($_POST['grant_all']) && ($_POST['grant_all'] == 'all') ){
			return 'all';
		}

		$_POST += array('grant'=>array());
		$array = $_POST['grant'];

		//cannot remove self from Admin_Users
		if( $username == $gpAdmin['username'] ){
			$array = array_merge($array, array('Admin_Users'));
		}

		if( !is_array($array) ){
			return '';
		}

		$keys = array_keys($this->possible_permissions);
		$array = array_intersect($keys,$array);
		return implode(',',$array);
	}


	/**
	 * Return the posted file editing permissions
	 *
	 */
	function GetEditingPermissions(){
		global $gp_titles;

		if( isset($_POST['editing_all']) && ($_POST['editing_all'] == 'all') ){
			return 'all';
		}

		$_POST += array('titles'=>array());
		$array = $_POST['titles'];
		if( !is_array($array) ){
			return '';
		}

		$keys = array_keys($gp_titles);
		$array = array_intersect($keys,$array);
		if( count($array) > 0 ){
			return ','.implode(',',$array).',';
		}
		return '';
	}



	function SaveUserFile($refresh = true ){
		global $langmessage, $dataDir;

		if( !gpFiles::SaveData($dataDir.'/data/_site/users.php','users',$this->users) ){
			message($langmessage['OOPS']);
			return false;
		}

		if( $refresh && isset($_GET['gpreq']) && $_GET['gpreq'] == 'json' ){
			message($langmessage['SAVED'].' '.$langmessage['REFRESH']);
		}else{
			message($langmessage['SAVED']);
		}
		return true;
	}


	/**
	 * Show all users and their permissions
	 *
	 */
	function ShowForm(){
		global $langmessage;


		echo '<h2>'.$langmessage['user_permissions'].'</h2>';

		ob_start();
		echo '<table class="bordered full_width">';
		echo '<tr><th>';
		echo $langmessage['username'];
		echo '</th><th>';
		echo 'Password Algorithm';
		echo '</th><th>';
		echo $langmessage['permissions'];
		echo '</th><th>';
		echo $langmessage['file_editing'];
		echo '</th><th>';
		echo $langmessage['options'];
		echo '</th></tr>';

		foreach($this->users as $username => $userinfo){

			echo '<tr><td>';
			echo $username;

			//algorithm
			echo '</td><td>';
			$this->PassAlgo($userinfo);


			//admin permissions
			echo '</td><td>';
				if( $userinfo['granted'] == 'all' ){
					echo 'all';
				}elseif( !empty($userinfo['granted']) ){

					$permissions = explode(',',$userinfo['granted']);
					$list = array();
					foreach($permissions as $permission){
						if( isset($this->possible_permissions[$permission]) ){
							$list[] = $this->possible_permissions[$permission];
						}
					}
					if( count($list) ){
						echo implode(', ',$list);
					}else{
						echo $langmessage['None'];
					}
				}else{
					echo $langmessage['None'];
				}

			echo '</td>';

			//file editing
			echo '<td>';
			if( !isset($userinfo['editing']) ){
				$userinfo['editing'] = 'all';
			}
			if( $userinfo['editing'] == 'all' ){
				echo $langmessage['All'];
			}else{
				$count = 0;
				$counts = count_chars( $userinfo['editing'],1 ); //count the commas
				if( !empty($userinfo['editing']) && isset($counts[44]) ){
					$count = $counts[44]-1;
				}
				if( $count == 0 ){
					echo $langmessage['None'];
				}else{
					echo sprintf($langmessage['%s Pages'],$count);
				}
			}

			echo '</td>';

			//options
			echo '<td>';
			echo common::Link('Admin_Users',$langmessage['details'],'cmd=details&username='.$username);
			echo ' &nbsp; ';
			echo common::Link('Admin_Users',$langmessage['password'],'cmd=changepass&username='.$username);
			echo ' &nbsp; ';

			$title = sprintf($langmessage['generic_delete_confirm'],htmlspecialchars($username));
			echo common::Link('Admin_Users',$langmessage['delete'],'cmd=rm&username='.$username,array('data-cmd'=>'postlink','title'=>$title,'class'=>'gpconfirm'));
			echo '</td>';
			echo '</tr>';
		}
		echo '<tr><th colspan="5">';
		echo common::Link('Admin_Users',$langmessage['new_user'],'cmd=newuserform');
		echo '</th>';

		echo '</table>';

		$content = ob_get_clean();

		if( $this->has_weak_pass ){
			echo '<p class="gp_notice"><b>Warning:</b> ';
			echo 'Weak password algorithms are being used for one or more users. To fix this issue, reset the user\'s password. ';
			echo '</p>';
		}

		echo $content;
	}


	/**
	 * Display the password algorithm being used for the user
	 *
	 */
	function PassAlgo($userinfo){

		$algo = gpsession::PassAlgo($userinfo);
		switch($algo){
			case 'md5':
			case 'sha1':
			$this->has_weak_pass = true;
			echo '<span style="color:red">'.$algo.'</span>';
			return;
		}
		echo $algo;
	}


	/**
	 * Display form for adding new admin user
	 *
	 */
	function NewUserForm(){
		global $langmessage;

		$_POST += array('username'=>'','email'=>'','grant'=>array(),'grant_all'=>'all','editing_all'=>'all');

		echo '<form action="'.common::GetUrl('Admin_Users').'" method="post" id="permission_form">';
		echo '<table class="bordered" style="width:95%">';
		echo '<tr><th colspan="2">';
			echo $langmessage['new_user'];
			echo '</th></tr>';
		echo '<tr><td>';
			echo $langmessage['username'];
			echo '</td><td>';
			echo '<input type="text" name="username" value="'.htmlspecialchars($_POST['username']).'" class="gpinput"/>';
			echo '</td></tr>';
		echo '<tr><td>';
			echo $langmessage['password'];
			echo '</td><td>';
			echo '<input type="password" name="password" value="" class="gpinput"/>';
			echo '</td></tr>';
		echo '<tr><td>';
			echo str_replace(' ','&nbsp;',$langmessage['repeat_password']);
			echo '</td><td>';
			echo '<input type="password" name="password1" value="" class="gpinput"/>';
			echo '</td></tr>';

		$_POST['granted'] = $this->GetPostedPermissions(false);
		$_POST['editing'] = $this->GetEditingPermissions();
		$this->DetailsForm($_POST);


		echo '<tr><td>';
			echo '</td><td>';
			echo '<input type="hidden" name="cmd" value="newuser" />';
			echo ' <input type="submit" name="aaa" value="'.$langmessage['save'].'" class="gpsubmit"/>';
			echo ' <input type="reset" class="gpsubmit"/>';
			echo ' <input type="submit" name="cmd" value="'.$langmessage['cancel'].'" class="gpcancel"/>';
			echo '</td>';
			echo '</tr>';

		echo '</table>';
		echo '</form>';
	}


	/**
	 * Display permission options
	 *
	 */
	function DetailsForm( $values=array(), $username=false ){
		global $langmessage, $gp_titles;

		$values += array('granted'=>'','email'=>'');

		//email address
		echo '<tr><td>';
		echo str_replace(' ','&nbsp;',$langmessage['email_address']);
		echo '</td><td>';
		echo '<input type="text" name="email" value="'.htmlspecialchars($values['email']).'" class="gpinput"/>';
		echo '</td></tr>';


		//admin permissions
		echo '<tr><td>';
		echo str_replace(' ','&nbsp;',$langmessage['grant_usage']);
		echo '</td><td class="all_checkboxes">';

		$all = false;
		$current = $values['granted'];
		$checked = '';
		if( $current == 'all' ){
			$all = true;
			$checked = ' checked="checked" ';
		}else{
			$current = ','.$current.',';
		}

		echo '<p><label class="select_all"><input type="checkbox" class="select_all" name="grant_all" value="all" '.$checked.'/> '.$langmessage['All'].'</label></p>';

		foreach($this->possible_permissions as $permission => $label){
			$checked = '';
			if( $all ){
				$checked = ' checked="checked" ';
			}elseif( strpos($current,','.$permission.',') !== false ){
				$checked = ' checked="checked" ';
			}

			echo '<label class="all_checkbox"><input type="checkbox" name="grant[]" value="'.$permission.'" '.$checked.'/> '.$label.'</label> ';
		}

		echo '</td></tr>';

		//file editing
		echo '<tr><td>';
		echo $langmessage['file_editing'];
		echo '</td><td class="all_checkboxes">';

		$editing_values = $values['editing'];
		$all = ($editing_values == 'all');
		$checked = $all ? ' checked="checked" ' : '';
		echo '<p><label class="select_all"><input type="checkbox" class="select_all" name="editing_all" value="all" '.$checked.'/> '.$langmessage['All'].'</label></p>';

		echo '<div style="height:200px;overflow:auto;">';

		$ordered = array();
		foreach($gp_titles as $index => $info){
			$ordered[$index] = strip_tags(common::GetLabelIndex($index));
		}

		uasort($ordered,'strnatcasecmp');

		foreach($ordered as $index => $label){
			$checked = '';
			if( $all ){
				$checked = ' checked="checked" ';
			}elseif( strpos($editing_values,','.$index.',') !== false ){
				$checked = ' checked="checked" ';
			}

			echo '<label class="all_checkbox"><input type="checkbox" name="titles[]" value="'.$index.'" '.$checked.'/> '.strip_tags($label).'</label> ';
		}

		echo '</div>';
		echo '</td></tr>';

		//echo '<tr><td colspan="2">'.showArray($values).'</td></tr>';
	}

	/**
	 * Display form for changing a user password
	 *
	 */
	function ChangePass(){
		global $langmessage;

		$username =& $_REQUEST['username'];
		if( !isset($this->users[$username]) ){
			message($langmessage['OOPS']);
			return;
		}


		echo '<form action="'.common::GetUrl('Admin_Users').'" method="post">';
		echo '<input type="hidden" name="cmd" value="resetpass" />';
		echo '<input type="hidden" name="username" value="'.htmlspecialchars($username).'" />';

		echo '<table class="bordered">';
		echo '<tr><th colspan="2">';
			echo $langmessage['change_password'];
			echo ' - ';
			echo $username;
			echo '</th></tr>';
		echo '<tr><td>';
			echo $langmessage['new_password'];
			echo '</td><td>';
			echo '<input type="password" name="password" value="" class="gpinput"/>';
			echo '</td></tr>';
		echo '<tr><td>';
			echo str_replace(' ','&nbsp;',$langmessage['repeat_password']);
			echo '</td><td>';
			echo '<input type="password" name="password1" value="" class="gpinput"/>';
			echo '</td></tr>';
		echo '<tr><td>';
			echo '</td><td>';
			echo '<input type="submit" name="aaa" value="'.$langmessage['save'].'" class="gpsubmit" />';
			echo ' <input type="submit" name="cmd" value="'.$langmessage['cancel'].'" class="gpcancel" />';
			echo '</td></tr>';
		echo '</table>';
		echo '</form>';
	}

	/**
	 * Save a user's new password
	 *
	 */
	function ResetPass(){
		global $langmessage, $config;

		if( !$this->CheckPasswords() ){
			return false;
		}

		$username = $_POST['username'];
		if( !isset($this->users[$username]) ){
			message($langmessage['OOPS']);
			return false;
		}

		$pass_hash								= gpsession::PassAlgo($this->users[$username]);
		$this->users[$username]['password']		= common::hash($_POST['password'],$pass_hash);

		return $this->SaveUserFile();
	}

	/**
	 * Check the posted passwords
	 * Make sure they're not empty and the match each other
	 *
	 */
	function CheckPasswords(){
		global $langmessage;

		//see also admin_users for password checking
		if( ($_POST['password']=="") || ($_POST['password'] !== $_POST['password1'])  ){
			message($langmessage['invalid_password']);
			return false;
		}
		return true;
	}

	function GetUsers(){

		$this->users		= gpFiles::Get('_site/users');

		//fix the editing value
		foreach($this->users as $username => $userinfo){
			$userinfo += array('granted'=>'');
			admin_tools::EditingValue($userinfo);
			$this->users[$username] = $userinfo;
		}
	}



	/**
	 * Display the permission options for a file
	 *
	 */
	function FilePermissions(){
		global $gp_titles, $langmessage;

		$indexes 		= $this->RequestedIndexes();
		if( !$indexes ){
			return;
		}

		$count			= count($indexes);
		$first_index	= $indexes[0];


		echo '<div class="inline_box">';
		echo '<form action="'.common::GetUrl('Admin_Users').'" method="post">';
		echo '<input type="hidden" name="cmd" value="save_file_permissions">';
		echo '<input type="hidden" name="index" value="'.htmlspecialchars($_REQUEST['index']).'">';


		//heading
		echo '<h2>'.common::Link('Admin_Users',$langmessage['user_permissions']).' &#187; <i>';
		if( $count > 1 ){
			echo sprintf($langmessage['%s Pages'],$count);
		}else{
			echo strip_tags(common::GetLabelIndex($indexes[0]));
		}
		echo '</i></h2>';


		//list of files
		if( $count > 1 ){
			$labels = array();
			foreach( $indexes as $index ){
				$labels[] = strip_tags(common::GetLabelIndex($index));
			}
			echo '<p>';
			echo implode(', ',$labels);
			echo '</p>';
		}


		//list of users
		echo '<div class="all_checkboxes">';
		foreach($this->users as $username => $userinfo){
			$attr = '';
			if( $userinfo['editing'] == 'all'){
				$attr = ' checked="checked" disabled="disabled"';
			}elseif(strpos($userinfo['editing'],','.$first_index.',') !== false ){
				$attr = ' checked="checked"';
			}
			echo '<label class="all_checkbox">';
			echo '<input type="checkbox" name="users['.htmlspecialchars($username).']" value="'.htmlspecialchars($username).'" '.$attr.'/>';
			echo $username;
			echo '</label> ';
		}
		echo '</div>';

		echo '<p>';
		echo '<input type="submit" name="aaa" value="'.$langmessage['save'].'" class="gpabox gpsubmit" />';
		echo ' <input type="submit" name="cmd" value="'.$langmessage['cancel'].'" class="admin_box_close gpcancel" />';
		echo '</p>';

		echo '</form>';
		echo '</div>';
	}


	/**
	 * Save the permissions for a specific file
	 *
	 */
	function SaveFilePermissions(){
		global $gp_titles, $langmessage, $gp_index, $gpAdmin;

		$indexes 		= $this->RequestedIndexes();
		if( !$indexes ){
			return;
		}


		foreach($this->users as $username => $userinfo){

			if( $userinfo['editing'] == 'all'){
				continue;
			}

			$editing = $userinfo['editing'];

			foreach($indexes as $index){

				if( isset($_POST['users'][$username]) ){
					$editing .= $index.',';
				}else{
					$editing = str_replace( ','.$index.',', ',', $editing);
				}
			}

			$editing = explode(',',trim($editing,','));
			$editing = array_intersect($editing,$gp_index);
			if( count($editing) ){
				$editing = ','.implode(',',$editing).',';
			}else{
				$editing = '';
			}

			$this->users[$username]['editing'] = $editing;
			$is_curr_user = ($gpAdmin['username'] == $username);
			$this->UserFileDetails($username,$is_curr_user);
		}

		return $this->SaveUserFile(false);
	}


	/**
	 * Get the menu indexes
	 *
	 */
	function RequestedIndexes(){
		global $langmessage, $gp_titles;

		$_REQUEST		+= array('index'=>'');
		$indexes		= explode(',',$_REQUEST['index']);

		if( !$indexes ){
			message($langmessage['OOPS'].' Invalid Title (1)');
			return;
		}

		$cleaned = array();
		foreach($indexes as $index){
			if( !isset($gp_titles[$index]) ){
				continue;
			}
			$cleaned[] = $index;
		}

		if( !$cleaned ){
			message($langmessage['OOPS'].' Invalid Title (2)');
			return;
		}

		return $cleaned;
	}

}





