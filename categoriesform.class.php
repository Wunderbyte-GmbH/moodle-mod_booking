<?php

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}

require_once $CFG->libdir.'/formslib.php';

class mod_booking_categories_form extends moodleform {
	var $options = array();

	function showSubCategories($cat_id, $dashes = '', $DB, $options){
		$dashes .= '&nbsp;&nbsp;';
		$categories = $DB->get_records('booking_category', array('cid' => $cat_id));
		if(count((array)$categories) > 0){
			foreach ($categories as $category) {
					$options[$category->id] = $dashes . $category->name;
					$options = $this->showSubCategories($category->id, $dashes, $DB, $options);
			}
		}

		return $options;
	}

	function definition() {
		global $CFG, $DB, $COURSE;

		$categories = $DB->get_records('booking_category', array('course' => $COURSE->id, 'cid' => 0));

		$options = array(0 => get_string('rootcategory', 'mod_booking'));

		foreach ($categories as $category) {
				$options[$category->id] = $category->name;
				$options = $this->showSubCategories($category->id, '', $DB, $options);
		}

		$context = context_system::instance();

		$mform    = $this->_form;

		//-------------------------------------------------------------------------------
		$mform->addElement('header', 'general', get_string('general', 'form'));

		$mform->addElement('text', 'name', get_string('categoryname', 'booking'), array('size'=>'64'));
		$mform->addRule('name', null, 'required', null, 'client');
		$mform->setType('name', PARAM_TEXT);

		$mform->addElement('select', 'cid', get_string('selectcategory', 'mod_booking'), $options);
		$mform->setDefault('cid', 0);
		$mform->addRule('name', null, 'required', null, 'client');

		$mform->addElement('hidden', 'courseid');
		$mform->setType('courseid', PARAM_RAW);

		$mform->addElement('hidden', 'course');
		$mform->setType('course', PARAM_RAW);

		$mform->addElement('hidden', 'id');
		$mform->setType('id', PARAM_RAW);

		$this->add_action_buttons();

	}
}

?>