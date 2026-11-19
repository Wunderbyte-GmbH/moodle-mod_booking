<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Form element "sortable select": choose several options and put them in an order.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// The forms library (PEAR QuickForm) defines the names of the methods and of the property for the help button.
// phpcs:disable moodle.NamingConventions.ValidFunctionName.LowercaseMethod
// phpcs:disable moodle.NamingConventions.ValidVariableName.MemberNameUnderscore
// phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore

namespace mod_booking\form\element;

use core\output\renderer_base;
use core\output\templatable;
use HTML_QuickForm_element;
use HTML_QuickForm_html;
use MoodleQuickForm;
use MoodleQuickForm_Renderer;
use templatable_form_element;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/pear/HTML/QuickForm/element.php');
require_once($CFG->libdir . '/pear/HTML/QuickForm/html.php');
require_once($CFG->libdir . '/form/templatable_form_element.php');

/**
 * Form element "sortable select": choose several options and put them in an order.
 *
 * The value of the element is the list of the chosen option values in the order the user gave them,
 * e.g. ['titleprefix', 'text']. A multiple select cannot do this: the browser submits its options in the
 * order of the options, not in the order they were chosen.
 *
 * Usage:
 *
 *     sortableselect::register();
 *     $mform->addElement('sortableselect', 'columns', get_string('columns', 'mod_x'), $options, [
 *         'noselectionstring' => get_string('nocolumns', 'mod_x'),
 *     ]);
 *     $mform->setDefault('columns', ['b', 'a']);
 *
 * $options is an array [value => label]. The attributes may hold these texts of the element:
 * - noselectionstring: shown while nothing is chosen
 * - choosestring: label and first entry of the select that offers the options
 * - addstring: text of the button that adds the chosen option
 *
 * Only values of $options are accepted. A default value that is not (or no longer) part of $options is
 * kept as long as the user does not remove it, so that saving the form does not lose stored data silently.
 *
 * The class has no dependency on mod_booking besides its strings, templates and its JS module.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sortableselect extends HTML_QuickForm_element implements templatable {
    use templatable_form_element {
        export_for_template as export_for_template_base;
    }

    /** @var string Name of the element type, as used in addElement(). */
    public const TYPE = 'sortableselect';

    /**
     * Value of a hidden input that is always submitted, like in a multiple select of core: without it
     * an empty list would submit nothing and could not be told apart from an element that is not in the form.
     *
     * @var string
     */
    public const EMPTYVALUE = '_qf__force_multiselect_submission';

    /** @var string[] Attributes that are texts of the element and no HTML attributes. */
    protected const TEXTATTRIBUTES = ['noselectionstring', 'choosestring', 'addstring'];

    /** @var array The options that can be chosen: value => label. */
    protected array $options = [];

    /** @var array Default values that are not part of the options: value => value. */
    protected array $legacyvalues = [];

    /** @var string[] The chosen values in their order. */
    protected array $values = [];

    /** @var array Texts of the element, see TEXTATTRIBUTES. */
    protected array $texts = [];

    /** @var string HTML of the help button, set by MoodleQuickForm::addHelpButton(). */
    public $_helpbutton = '';

    /**
     * Constructor.
     *
     * All arguments are optional, because QuickForm creates the element without arguments and calls the
     * constructor a second time (event createElement).
     *
     * @param string|null $elementname name of the element
     * @param string|null $elementlabel label of the element
     * @param array|null $options the options that can be chosen: value => label
     * @param array|string|null $attributes HTML attributes and the texts noselectionstring, choosestring, addstring
     */
    public function __construct($elementname = null, $elementlabel = null, $options = null, $attributes = null) {
        if (is_array($attributes)) {
            foreach (self::TEXTATTRIBUTES as $text) {
                if (isset($attributes[$text])) {
                    $this->texts[$text] = (string)$attributes[$text];
                    unset($attributes[$text]);
                }
            }
        }
        parent::__construct($elementname, $elementlabel, $attributes);
        $this->_type = self::TYPE;
        $this->_persistantFreeze = true;
        foreach ((array)$options as $value => $label) {
            $this->options[(string)$value] = (string)$label;
        }
    }

    /**
     * Makes the element type known to the forms library. Call it before addElement('sortableselect', ...).
     *
     * @return void
     */
    public static function register(): void {
        // Registering twice does no harm, the type is just set again.
        MoodleQuickForm::registerElementType(self::TYPE, __FILE__, self::class);
    }

    /**
     * Sets the name of the element.
     *
     * @param string $name
     * @return void
     */
    public function setName($name) {
        $this->updateAttributes(['name' => $name]);
    }

    /**
     * Returns the name of the element.
     *
     * @return string|null
     */
    public function getName() {
        return $this->getAttribute('name');
    }

    /**
     * Returns the HTML of the help button.
     *
     * @return string
     */
    public function getHelpButton() {
        return $this->_helpbutton;
    }

    /**
     * Sets the chosen values.
     *
     * @param array|string|null $value list of values or comma separated values
     * @return void
     */
    public function setValue($value) {
        $this->values = self::clean_values($value);
    }

    /**
     * Returns the chosen values in their order. Values that may not be chosen are left out.
     *
     * @return string[]
     */
    public function getValue() {
        return $this->filter_allowed($this->values);
    }

    /**
     * Turns a submitted or stored value into a list of strings without empty entries and duplicates.
     *
     * @param mixed $value list of values or comma separated values
     * @return string[]
     */
    public static function clean_values($value): array {
        if ($value === null || $value === self::EMPTYVALUE) {
            return [];
        }
        if (!is_array($value)) {
            $value = explode(',', (string)$value);
        }
        $values = [];
        foreach ($value as $onevalue) {
            if (!is_scalar($onevalue)) {
                continue;
            }
            $onevalue = trim((string)$onevalue);
            if ($onevalue !== '' && $onevalue !== self::EMPTYVALUE) {
                // The key removes duplicates and keeps the first position of a value.
                $values[$onevalue] = $onevalue;
            }
        }
        return array_values($values);
    }

    /**
     * Returns the values that may be chosen: the options and the default values that are no options.
     *
     * @param string[] $values
     * @return string[] the allowed values of $values, in the order of $values
     */
    protected function filter_allowed(array $values): array {
        return array_values(array_filter(
            $values,
            fn($value) => isset($this->options[$value]) || isset($this->legacyvalues[$value])
        ));
    }

    /**
     * Called by the form whenever something happens to the element.
     *
     * Before the value is updated, the default and constant values of the form are checked for values that
     * are no options. Those were stored earlier, so they are trusted and kept. Submitted values are never
     * added here: the user must not be able to invent options.
     *
     * @param string $event name of the event
     * @param mixed $arg arguments of the event
     * @param object $caller the form
     * @return bool
     */
    public function onQuickFormEvent($event, $arg, &$caller) {
        if ($event === 'updateValue') {
            foreach ([$caller->_defaultValues, $caller->_constantValues] as $trustedvalues) {
                foreach (self::clean_values($this->_findValue($trustedvalues)) as $value) {
                    if (!isset($this->options[$value])) {
                        $this->legacyvalues[$value] = $value;
                    }
                }
            }
        }
        return parent::onQuickFormEvent($event, $arg, $caller);
    }

    /**
     * Checks the submitted value on the server: only options (and kept default values) may be submitted.
     *
     * @param mixed $value the submitted value
     * @return string|null error message, null if the value is valid
     */
    public function validateSubmitValue($value) {
        $values = self::clean_values($value);
        $invalid = array_diff($values, $this->filter_allowed($values));
        if (!empty($invalid)) {
            return get_string('sortableselect:invalid', 'mod_booking', s(implode(', ', $invalid)));
        }
        return null;
    }

    /**
     * Returns the value for get_data(): always a list, also when nothing is chosen.
     *
     * @param array $submitvalues the submitted values of the form
     * @param bool $assoc true to return [name => value]
     * @return mixed
     */
    public function exportValue(&$submitvalues, $assoc = false) {
        $value = $this->_findValue($submitvalues);
        if ($value === null) {
            $value = $this->values;
        }
        return $this->_prepareValue($this->filter_allowed(self::clean_values($value)), $assoc);
    }

    /**
     * Label of a value: the label of the option, or the value itself for a default value without option.
     *
     * @param string $value
     * @return string
     */
    protected function get_value_label(string $value): string {
        return $this->options[$value] ?? $value;
    }

    /**
     * Returns the data for the template mod_booking/form/sortableselect.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        $this->_generateId();
        $context = $this->export_for_template_base($output);
        $name = $this->getName();
        $values = $this->getValue();

        $items = [];
        foreach ($values as $index => $value) {
            $items[] = [
                'name' => $name,
                'value' => $value,
                'label' => $this->get_value_label($value),
                'position' => $index + 1,
                'first' => $index === 0,
                'last' => $index === count($values) - 1,
                'legacy' => !isset($this->options[$value]),
            ];
        }
        $options = [];
        foreach ($this->options as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label, 'chosen' => in_array($value, $values, true)];
        }

        $context['name'] = $name;
        $context['plainlabel'] = trim(html_to_text((string)$this->getLabel(), 0, false));
        $context['emptyvalue'] = self::EMPTYVALUE;
        $context['items'] = $items;
        $context['hasitems'] = !empty($items);
        $context['options'] = $options;
        $context['noselectionstring'] = $this->texts['noselectionstring'] ?? get_string('sortableselect:empty', 'mod_booking');
        $context['choosestring'] = $this->texts['choosestring'] ?? get_string('sortableselect:choose', 'mod_booking');
        $context['addstring'] = $this->texts['addstring'] ?? get_string('sortableselect:add', 'mod_booking');
        return $context;
    }

    /**
     * Returns the HTML of the element without label, help and error message.
     *
     * @return string
     */
    public function toHtml() {
        global $OUTPUT, $PAGE;

        if ($this->_flagFrozen) {
            return $this->getFrozenHtml();
        }
        $context = $this->export_for_template($OUTPUT);
        $PAGE->requires->js_call_amd('mod_booking/form/sortableselect', 'init', [$context['id']]);
        return $OUTPUT->render_from_template('mod_booking/form/sortableselect', $context);
    }

    /**
     * Returns the HTML of the frozen element: the chosen entries as a list that cannot be changed.
     *
     * @return string
     */
    public function getFrozenHtml() {
        global $OUTPUT;

        $context = $this->export_for_template($OUTPUT);
        $context['persistent'] = !empty($this->_persistantFreeze);
        return $OUTPUT->render_from_template('mod_booking/form/sortableselect_frozen', $context);
    }

    /**
     * Renders the element with label, help and error message.
     *
     * The renderer of core looks for the template core_form/element-TYPE, which a plugin cannot provide, and
     * then uses a wrapper without the grid of the theme. So the element renders the wrapper itself, with a
     * template that extends core_form/element-template like the templates of the core elements do.
     * Inside of a group and for other renderers, the default way (toHtml()) is used.
     *
     * @param object $renderer the renderer of the form
     * @param bool $required whether the element is required
     * @param string|null $error error message of the element
     * @return void
     */
    public function accept(&$renderer, $required = false, $error = null) {
        global $OUTPUT;

        if (!($renderer instanceof MoodleQuickForm_Renderer) || !empty($renderer->_inGroup)) {
            parent::accept($renderer, $required, $error);
            return;
        }

        $elementcontext = $this->export_for_template($OUTPUT);
        $elementcontext['wrapperid'] = 'fitem_' . $elementcontext['id'];
        $elementcontext['html'] = $this->toHtml();
        $html = $OUTPUT->render_from_template('mod_booking/form/element-sortableselect', [
            'element' => $elementcontext,
            'label' => $this->getLabel(),
            'required' => $required,
            'advanced' => isset($renderer->_advancedElements[$this->getName()]),
            'helpbutton' => $this->getHelpButton(),
            'error' => $error,
        ]);
        // The renderer takes finished HTML only from an element of the type "html".
        $htmlelement = new HTML_QuickForm_html($html);
        $renderer->renderHtml($htmlelement);
    }
}
