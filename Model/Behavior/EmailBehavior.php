<?php
/**
 * email behavior
 *
 * The workhorse for the MiEmail model. By using a behavior it is easier to change settings
 * on a per project basis
 *
 * PHP version 5
 *
 * Copyright (c) 2008, Andy Dawson
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) 2008, Andy Dawson
 * @link          www.ad7six.com
 * @package       base
 * @subpackage    base.models.behaviors
 * @since         v 1.0
 * @license       http://www.opensource.org/licenses/mit-license.php The MIT License
 */

/**
 * EmailBehavior class
 *
 * @uses          ModelBehavior
 * @package       base
 * @subpackage    base.models.behaviors
 */
class EmailBehavior extends ModelBehavior {

/**
 * name property
 *
 * @var string 'Email'
 * @access public
 */
	public $name = 'Email';

/**
 * errors property
 *
 * @var array
 * @access public
 */
	public $errors = array();

/**
 * defaultSettings property
 *
 * @var array
 * @access protected
 */
	protected $_defaultSettings = array(
		'autoSend' => true,
		'charset' => 'utf-8',
		'delivery' => 'mail',
		'sendAs' => 'both',
		'config' => array('port'=> 25, 'host' => 'localhost', 'timeout' => 30,
			'username' => '', 'password' => ''),
		'xMailer' => 'CakePHP Email Component',
		'bcc' => array(),
		'cc' => array(),
		'behaviorMode' => null, // 'requestAction'
	);

/**
 * Email property
 *
 * Placeholder for Email compoment used when sending emails
 *
 * @var bool false
 * @access private
 */
	private $__email = false;

/**
 * controller property
 *
 * Placeholder for Email controller used when sending emails
 *
 * @var bool false
 * @access private
 */
	private $__controller = false;

/**
 * setup method
 *
 * @param mixed $Model
 * @param array $config
 * @return void
 * @access public
 */
	public function setup(&$Model, $config = array()) {
		$this->settings[$Model->alias] = Set::merge($this->_defaultSettings, $config);
	}

/**
 * afterFind method
 *
 * unserializes the data field
 *
 * @param mixed $Model
 * @param mixed $results
 * @param bool $primary
 * @return void
 * @access public
 */
	public function afterFind(&$Model, $results, $primary = false) {
		if (isset($results[0][$Model->alias])) {
			foreach ($results as $key => $result) {
				foreach ($result[$Model->alias] as $field => $value) {
					if (is_string($value) && isset($value[1]) && in_array($value[1], array(':', ';'))) {
						$results[$key][$Model->alias][$field] = unserialize($value);
					}
				}
			}
			if (!empty($results[0][$Model->alias]['subject'])) {
				$results[0][$Model->alias]['slug'] = $Model->slug($results[0][$Model->alias]['subject']);
			}
		} elseif (isset($results[$Model->alias])) {
			foreach ($results as $field => $value) {
				if (is_string($value) && in_array($value[1], array(':', ';'))) {
					$results[$field] = unserialize($value);
				}
			}
			if (!empty($result['subject'])) {
				$results['slug'] = $Model->slug($results['subject']);
			}
		}
		return $results;
	}

/**
 * afterSave method
 *
 * @param mixed $Model
 * @param mixed $created
 * @return void
 * @access public
 */
	public function afterSave(&$Model, $created) {
		if ($created || !$this->settings[$Model->alias]['autoSend']) {
			return;
		}
		$data = $Model->read();
		if ($data[$Model->alias]['status'] != 'pending' || $data[$Model->alias]['send_date'] > date('Y-m-d')) {
			return;
		}
		$this->__send($Model);
	}

/**
 * beforeSave method
 *
 * @param mixed $Model
 * @return void
 * @access public
 */
	public function beforeSave(&$Model) {
		if (!$Model->useTable) {
			return false;
		}
		$this->__defaults($Model);
		foreach ($Model->data[$Model->alias] as $key => $value) {
			if (is_array($value)) {
				$Model->data[$Model->alias][$key] = serialize($value);
			}
		}
		if (!$Model->id) {
			App::uses('CakeRequest', 'Utility');
			$Model->data[$Model->alias]['ip'] = ip2long(CakeRequest::clientIp());
		}
		return true;
	}

/**
 * beforeSend method
 *
 * Ensure all required data is present - set the Email component with the appropriate settings
 *
 * @param mixed $Model
 * @return void
 * @access public
 */
	public function beforeSend(&$Model) {
		foreach ($Model->data[$Model->alias] as $key => $value) {
			if (is_string($value) && strlen($value) > 1 && in_array($value[1], array(':', ';'))) {
				$Model->data[$Model->alias][$key] = unserialize($value);
			}
		}
		$Model->data[$Model->alias]['slug'] = $Model->slug($Model->data[$Model->alias]['subject']);
		$this->__defaults($Model);
		if ($this->settings[$Model->alias]['autoSend']) {
			if (!$this->__email) {
				App::import('Core', 'Controller');
				$this->__controller = new Controller();
				if (!isset($this->__controller->Session)) {
					App::uses('SessionComponent', 'Component'); 
					$this->__controller->Session = new SessionComponent(new ComponentCollection());
				}
				if (App::import('View', 'Mi.Mi')) {
					$this->__controller->view = 'Mi.Mi';
				}
				App::uses('CakeEmail', 'Network/Email'); 
				$this->__email = new CakeEmail();
				ClassRegistry::addObject('CakeEmail', $this->__email);
			}
			foreach($this->settings[$Model->alias] as $key => $val) {
				$this->__email->$key = $val;
			}
		}
		return true;
	}

/**
 * bindUsers method
 *
 * @param mixed $Model
 * @return void
 * @access public
 */
	public function bindUsers(&$Model) {
		$Model->bindModel(array(
			'belongsTo' => array(
				'FromUser' => array('className' => 'User', 'foreignKey' => 'from_user_id'),
				'ToUser' => array('className' => 'User', 'foreignKey' => 'to_user_id')
			)
		), false);
	}

/**
 * processQueue method
 *
 * If configured to save emails to the database, this method is what to call to process the queue
 *
 * @param mixed $Model
 * @param string $status
 * @param int $limit
 * @return void
 * @access public
 */
	public function processQueue(&$Model, $status = 'pending', $limit = 0) {
		$this->settings[$Model->alias]['autoSend'] = true;
        $conditions = array('status' => $status);
		foreach ($Model->find('all', compact('conditions', 'limit')) as $email) {
			$Model->create($email);
			if ($this->__send($Model, $email)) {
				$Model->saveField('status', 'sent');
			}
		}
	}

/**
 * sendPending method
 *
 * Sends an email saved to the database as pending
 *
 * @param mixed $Model
 * @param string $id
 */
	public function sendPending(&$Model, $id) {
		$this->settings[$Model->alias]['autoSend'] = true;
        $email = $Model->findById($id);
		$Model->create($email);
		if ($this->__send($Model, $email)) {
			$Model->saveField('status', 'sent');
		}
	}

/**
 * purge method
 *
 * Cleanup (processed) emails. example uses:
 * $Model->purge('private'); // Delete all sent private emails. Emails marked as private are not web accessible
 * $Model->purge('normal', 'sent', '2008-01-01); // Delete all sent normal emails - sent before this year.
 * $Model->purge(array('conditions' => $conditions)); // Delete all emails matching the conditions
 * $Model->purge(array('conditions' => array('type' => 'private'))); // Delete all private emails irrespective of
 * 	their status or date
 *
 * @param mixed $Model
 * @param string $type
 * @param string $status
 * @param mixed $date
 * @param array $conditions
 * @return void
 * @access public
 */
	public function purge(&$Model, $type = 'newsletter_copy', $status = 'sent', $date = null, $conditions = array()) {
		if (is_array($type)) {
			extract (array_merge(array('type' => 'newsletter_copy'), $type));
		} else {
			$conditions = am(compact('type', 'status'), $conditions);
			if ($date) {
				$conditions['send_date <'] = $date;
			}
		}
		if ($conditions) {
			return $Model->deleteAll($conditions);
		}
		return false;
	}

/**
 * resend method
 *
 * Resend the email, and link the new email to the original using the chain_id
 *
 * @param mixed $Model
 * @param mixed $id
 * @return void
 * @access public
 */
	public function resend(&$Model, $id) {
		$data = $Model->read(null, $id);
		if ($data[$Model->alias]['status'] == 'sent') {
			$Model->create();
			unset($data[$Model->alias]['id']);
			unset($data[$Model->alias]['created']);
			unset($data[$Model->alias]['modified']);
			$data[$Model->alias]['status'] = 'pending';
			$data[$Model->alias]['chain_id'] = $id;
		}
		return $Model->send($data);
	}

/**
 * send method
 *
 * Sends the email and or saves it to the database based on the model configuration.
 *
 * @param mixed $Model
 * @param mixed $data
 * @param string $status
 * @return void
 * @access public
 */
	public function send(&$Model, $data = null, $status = 'pending') {
		if (isset($data[$Model->alias])) {
			$Model->data = $data;
		} else {
			$Model->data = array($Model->alias => $data);
			$data[$Model->alias] = $data;	
		}
		$dbReturn = $return = false;
		if ($Model->useTable) {
			if (empty($Model->data[$Model->alias]['subject'])) {
				$_subject = Inflector::humanize(Inflector::underscore(
					str_replace('/', ' ', $Model->data[$Model->alias]['template'])));
				$Model->data[$Model->alias]['subject'] = __d('email_subjects',  $_subject, true);
			}
			$merge[$Model->alias]['status'] = $status;
			$_save = array_merge($merge, $data);
			$_save = $_save[$Model->alias];
			$Model->create();
			if ($Model->save($_save)) {	
				$dbReturn = true;
			} else {
				$this->errors[] = 'not possible to save to db';
			}
		
		}
		
		if ($this->settings[$Model->alias]['autoSend'] && !isset($Model->data[$Model->alias]['send_date']) && $status == 'pending') {
			$return = $this->__send($Model);
		}
		return $return?true:$dbReturn;
	}

/**
 * send method
 *
 * Accepts the id of a mail, or directly the data array for the email to send. If sending a mail that has
 * already been sent - $force must be set to true for the email to be resent
 *
 * @param mixed $Model
 * @param mixed $id
 * @param bool $force
 * @return void
 * @access private
 */
	private function __send(&$Model, $id = null, $force = false) {	
		if (!empty($Model->data)) {
		} elseif (is_array($id)) {
			$Model->data =& $id;
			$Model->id = $Model->data[$Model->alias]['id'];
		} elseif ($id) {
			$Model->data = $Model->read(null, $id);
		} elseif ($Model->id) {
			$Model->data = $Model->read();
		} else {
			return false;
		}

		extract($Model->data[$Model->alias]);
		if (empty($subject)) {
			$_subject = Inflector::humanize(Inflector::underscore(str_replace('/', ' ', $template)));
			$subject = __d('email_subjects',  $_subject, true);
		}
		$Model->data[$Model->alias]['data']['id'] = $Model->id;
		
		if (!$Model->beforeSend()) {
			if ($Model->id) {
				$Model->saveField('status', 'dataProblem');
			} else {
				$Model->data[$Model->alias]['status'] = 'dataProblem';
			}
			return false;
		}
		
		if (isset($status) && $status == 'sent' && !$force) {
			$this->errors[] = 'Email already sent';
			return false;
		}
		
		if ($this->settings[$Model->alias]['behaviorMode'] === 'requestAction') {
			$data = compact('template', 'layout', 'from' , 'to', 'reply_to', 'cc', 'bcc', 'send_as', 'subject', 'data');
			$this->requestAction(array('plugin' => false, 'controller' => 'emails', 'action' => 'send'), array('data' => $data));
			$result = true;
		} else {
			$this->__email->reset();

			if (!empty($layout)) {
				$this->__email->layout = $layout;
			}
			if (!empty($this->settings[$Model->alias]['delivery'])) {
				$this->__email->transport(Inflector::classify($this->settings[$Model->alias]['delivery']));
				if (!empty($this->settings[$Model->alias]['config'])) {
					$this->__email->transportClass()->config($this->settings[$Model->alias]['config']);
				}
			}

			foreach(array('template', 'from', 'to', 'sender', 'replyTo', 'cc', 'bcc', 'subject') as $var) {				
				if (!empty($$var)) {
					$this->__email->{$var}($$var);
				}
			}

			$isEmail = true;
			$emailData = $Model->data;
			$data = $Model->data[$Model->alias]['data'];
			$this->__email->emailFormat($Model->data[$Model->alias]['send_as']);
			$this->__email->viewVars(compact('data', 'emailData', 'isEmail'));
			$result = $this->__email->send();
			
		}
		if ($result) {
			$result = 'sent';
		} else {
			$result = 'sendError';
		}

		if ($id) {
			if (is_array($id)) {
				$id[$Model->alias]['status'] = $result;
				$id[$Model->alias]['subject'] = $this->__email->subject();
			} else {
				$Model->id = $id;
				$Model->save(array(
					'subject' => $this->__email->subject(),
					'status' => $result
				), array(
					'validate' => false,
					'fieldList' => array('subject', 'status'),
					'callbacks' => false
				));
			}
		} else {
			$Model->data[$Model->alias]['status'] = $result;
		}
		return $result == 'sent';
	}

/**
 * defaults method
 *
 * Set the defaults
 *
 * @param mixed $Model
 * @return void
 * @access private
 */
	private function __defaults(&$Model) {
		$domain = substr(env('HTTP_BASE'), 1);
		if (!$domain) {
			$domain = APP_DIR;
		}
		$defaults = array(
			'layout' => 'default',
			'reply_to' => 'noreply@' . $domain,
			'from' => $domain . ' <system@' . $domain . '>',
			'cc' => $this->settings[$Model->alias]['cc'],
			'bcc' => $this->settings[$Model->alias]['bcc'],
			'send_as' => $this->settings[$Model->alias]['sendAs'],
			'send_date' => null,
			'type' => 'normal'
		);
		$Model->data[$Model->alias] = am($defaults, $Model->data[$Model->alias]);
	}
}
