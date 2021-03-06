<?php

namespace andreask\ium\classes;

/**
* This file is part of the phpBB Forum extension package
* IUM (Inactive User Manager).
*
* @copyright (c) 2016 by Andreas Kourtidis
* @license   GNU General Public License, version 2 (GPL-2.0)
*
* For full copyright and license information, please see
* the CREDITS.txt file.
*/

class ignore_user
{
	protected $db; 				/** DBAL driver for database use */
	protected $config_text;		/** Db config text	*/
	protected $log;				/** Log class for logging informatin */
	protected $auth;			/** Auth class to get admins and mods */
	protected $table_name;		/** Custome table for ease of use */

	public function __construct(\phpbb\db\driver\driver_interface $db, \phpbb\config\db_text $config_text, \phpbb\auth\auth $auth, \phpbb\log\log $log, $ium_reminder_table)
	{
		$this->db				=	$db;
		$this->log				=	$log;
		$this->auth				=	$auth;
		$this->config_text		=	$config_text;
		$this->table_name		=	$ium_reminder_table;
	}

	/**
	 *	Check if user exist in phpbb users table
	 *	@return mixed true if all users found or array of users that was not found.
	 */

	public function exist($users)
	{
		$user_list = [];
		$not_exist = [];
		$user_count = 0;
		$not_exist_count = 0;

		foreach ($users as $user)
		{
			$sql = 'SELECT user_id, username FROM ' . USERS_TABLE . " WHERE username = '" . $this->db->sql_escape($user) . "'";
			$result = $this->db->sql_query($sql);
			$user_fetch = $this->db->sql_fetchrow($result);

			// For any user that was not found, store them.
			if (!$user_fetch)
			{
				$not_exist[$not_exist_count]['username'] = $user;
				$not_exist_count++;
			}
			else if ($user !== $user_fetch['username'])
			{
				$not_exist[$not_exist_count]['username'] = $user;
				$not_exist_count++;
			}

			$this->db->sql_freeresult($result);
		}
		if ($not_exist)
		{
			return $not_exist;
		}
		return true;
	}

	/**
	 *  function ignore_user updates Custome table with existing or new users (in table).
	 *	with the dont_send flag so they will be ignored by the reminder.
	 *	@param	$username, array of username(s)
	 *	@param	$mode, 1 (default) auto 2 admin
	 *	@return	null
	 */

	public function ignore_user($username, $mode = 1)
	{
		/**
		*	We have to check if the given users exist or not in custome table 'ium_reminder'
		*	This is done by doing left join USERS_TABLE and ium_reminder. and selecting users
		*	that are null (don't exist) on ium_reminder.
		*/

		$sql_query = 'SELECT user_id, username
									FROM ' . USERS_TABLE . ' WHERE ' .
									$this->db->sql_in_set('username', $username ) . $this->ignore_groups();


		// $sql_array = array(
		// 	'SELECT'	=> 'p.user_id, p.username',
		// 	'FROM'		=> array(
		// 		USERS_TABLE =>	'p',
		// 		),
		// 	'LEFT_JOIN' => array(
		// 		array(
		// 			'FROM'	=> array($this->table_name	=>	'r'),
		// 			'ON'	=>	'p.user_id = r.user_id',
		// 			)
		// 		),
		// 	'WHERE'	=> $this->db->sql_in_set('p.username', $username ) . $this->ignore_groups()
		// 	. ' AND username is null');

		// $sql = $this->db->sql_build_query('SELECT', $sql_array);
		// $result = $this->db->sql_query($sql);
		$result = $this->db->sql_query($sql_query);
		$rows = [];

		// Store in an array.
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}

		// Always free the results
		$this->db->sql_freeresult($result);
		$clean = [];

		// if the above situation did not ocured just update, since all the users exist already.
		foreach ($rows as $user)
		{
			$this->update_user($user, $mode);
		}
	}

	 /**
	  * Function Updates dont_sent field on users table
	  *
	  * @param  array  		$user	Usernames
	  * @param  boolean		$action  true for set user to ignore false for unset ignore
	  * @param  boolean 	$user_id use user_id instead of username
	  * @return void
	  */
	public function update_user($user, $action, $user_id = false)
	{
		if ($user_id)
		{
			$username = $this->get_user_username($user);
		}
		$username = ($user_id) ? $username : $user;
		// $username = ($user_id) ? array_shift($username) : $user;
		$dont_send = $action;

		$data = array ('ium_dont_send' => $action);
		$sql = 'UPDATE ' . USERS_TABLE . '
				SET ' . $this->db->sql_build_array('UPDATE', $data) . '
				WHERE '. $this->db->sql_in_set('username', $username);
		$this->db->sql_query($sql);
	}

	/**
	 * Getter for username
	 * @param int user_id
	 * @return string username
	 */
	private function get_user_username($id)
	{

		$sql = 'SELECT username
							FROM ' . USERS_TABLE . '
							WHERE ' . $this->db->sql_in_set('user_id', $id);
		$result = $this->db->sql_query($sql);

		$usernames = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$usernames[] = $row['username'];
		}
		$this->db->sql_freeresult($result);

		return $usernames;
	}

	/**
	 * Returns a complete string of user_type and user_id that should be ignored by the queries.
	 * @return string Complete ignore statement for sql
	 */
	public function ignore_groups()
	{
		// Get administrator user_ids
		$administrators = $this->auth->acl_get_list(false, 'a_', false);
		$admin_ary = (!empty($administrators[0]['a_'])) ? $administrators[0]['a_'] : array();

		// Get moderator user_ids
		$moderators = $this->auth->acl_get_list(false, 'm_', false);
		$mod_ary = (!empty($moderators[0]['m_'])) ? $moderators[0]['m_'] : array();

		// Merge them together
		$admin_mod_array = array_unique(array_merge($admin_ary, $mod_ary));

		// Ignored group_ids
		$ignore = $this->config_text->get('andreask_ium_ignored_groups', '');
		$ignore = json_decode($ignore);
		if (!empty($ignore))
		{
			$ignore = ' AND ' . $this->db->sql_in_set('group_id', $ignore, true);
		}
		else
		{
			$ignore = '';
		}

		// Make an array of user_types to ignore
		$ignore_users_extra = array(USER_FOUNDER, USER_IGNORE);

		$text = ' AND '	. $this->db->sql_in_set('user_type', $ignore_users_extra, true) .'
				  		AND '	. $this->db->sql_in_set('user_id', $admin_mod_array, true) .'
							AND user_inactive_reason not in ('. INACTIVE_MANUAL .') AND user_id > ' . ANONYMOUS . $ignore;

		return $text;
	}

	public function get_groups($user_id)
	{
		$sql = 'SELECT group_id FROM ' . USER_GROUP_TABLE . '
				WHERE user_id = ' . (int) $user_id;

		$result = $this->db->sql_query($sql);

		$group_ids = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$group_ids[] = $row['group_id'];
		}

		$this->db->sql_freeresult($result);

		return $group_ids;
	}
}
