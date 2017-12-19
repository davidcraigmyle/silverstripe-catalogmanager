<?php

/**
 * Class CatalogDataObjectExtension
 */

namespace littlegiant\CatalogManager;

use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Versioned;

class CatalogDataObjectExtension extends DataExtension
{
    /**
     * @config
     * @var array
     */
    private static $parentClass;
    /**
     * @config
     * @var bool
     */
    private static $can_duplicate = true;
    /**
     * Name of the sorting column. SiteTree has a col named "Sort", we use this as default
     * @config
     * @var string
     */
    private static $sort_column = false;
    /**
     * @config
     * @var bool
     */
    private static $automatic_live_sort = true;
    /**
     * @config
     * @var array
     */
    private static $db = array(
        'Sort' => 'Int'
    );
    /**
     * @config
     * @var array
     */
    private static $summary_fields = array(
        'isPublishedNice' => 'Enabled'
    );

    /**
     * Adds functionality to CMS fields
     *
     * @param FieldList $fields
     * @throws Exception
     */
    public function updateCMSFields(FieldList $fields)
    {
        $fields->removeByName('Version');
        $fields->removeByName('Versions');

        $parentClass = $this->getParentClasses();

        if ($pages = DataObject::get()->filter(array('ClassName' => array_values($parentClass)))) {
            if ($pages->exists()) {
                if ($pages->count() == 1) {
                    $fields->push(HiddenField::create('ParentID', 'ParentID', $pages->first()->ID));
                } else {
                    $parentID = $this->owner->ParentID ?: $pages->first()->ID;
                    $fields->push(DropdownField::create('ParentID', _t('CatalogManager.PARENTPAGE', 'Parent Page'), $pages->map('ID', 'Title'), $parentID));
                }
            } else {
                throw new Exception('You must create a parent page of class ' . implode(',', $parentClass));
            }
        } else {
            throw new Exception('Parent class ' . implode(',', $parentClass) . ' does not exist.');
        }
    }

    /**
     * Returns if this object is new or not
     *
     * @return bool
     */
    public function isNew()
    {
        $id = $this->owner->ID;
        if (empty($id)) {
            return true;
        }
        if (is_numeric($id)) {
            return false;
        }
    }

    /**
     * Returns if this object is published or not.
     *
     * @return bool
     */
    public function isPublished()
    {
        if ($this->isNew()) {
            return false;
        }

        $table = $this->owner->class;

        while (($p = get_parent_class($table)) !== 'DataObject') {
            $table = $p;
        }

        return (bool)DB::query("SELECT \"ID\" FROM \"{$table}_Live\" WHERE \"ID\" = {$this->owner->ID}")->value();
    }

    /**
     * Helper function to return nice booleans for GridFields
     *
     * @param $value
     * @return string
     */
    protected function getBooleanNice($value)
    {
        return $value ? 'Yes' : 'No';
    }

    /**
     * Nice string to tell if the object is published or not
     *
     * @return mixed
     */
    public function isPublishedNice()
    {
        return $this->getBooleanNice($this->isPublished());
    }

    /**
     * Nice string to tell if the object is modified or not
     *
     * @return mixed
     */
    public function isModifiedNice()
    {
        return $this->getBooleanNice($this->stagesDiffer('Stage', 'Live'));
    }

    /**
     * Publishes an object
     *
     * @return bool
     */

    public function doPublish()
    {
        $original = Versioned::get_one_by_stage($this->owner->ClassName, "Live", "\"{$this->owner->ClassName}\".\"ID\" = {$this->owner->ID}");
        if (!$original) {
            $original = new $this->owner->ClassName();
        }

        //$this->PublishedByID = Member::currentUser()->ID;
        $this->owner->write();
        $this->owner->publish("Stage", "Live");

        DB::query("UPDATE \"{$this->owner->ClassName}_Live\"
			SET \"Sort\" = ( SELECT \"{$this->owner->ClassName}\".\"Sort\" FROM \"{$this->owner->ClassName}\" WHERE \"{$this->owner->ClassName}\".\"ID\" = \"{$this->owner->ClassName}_Live\".\"ID\")
			WHERE EXISTS ( SELECT \"{$this->owner->ClassName}_Live\".\"Sort\" FROM \"{$this->owner->ClassName}\" WHERE \"{$this->owner->ClassName}\".\"ID\" = \"{$this->owner->ClassName}_Live\".\"ID\")");

        return true;
    }

    /**
     * Unpublishes an object
     *
     * @return bool
     */
    public function doUnpublish()
    {
        if (!$this->owner->ID) {
            return false;
        }
        $origStage = Versioned::current_stage();
        Versioned::reading_stage('Live');
        // This way our ID won't be unset
        $clone = clone $this;
        $clone->owner->delete();
        Versioned::reading_stage($origStage);
        // If we're on the draft site, then we can update the status.
        // Otherwise, these lines will resurrect an inappropriate record
        if (DB::query("SELECT \"ID\" FROM \"{$this->owner->ClassName}\" WHERE \"ID\" = {$this->owner->ID}")->value()
            && Versioned::current_stage() != 'Live'
        ) {
            $this->owner->write();
        }

        return true;
    }

    /**
     * Returns the parent classes defined from the config as an array
     * @return array
     */
    public function getParentClasses()
    {
        $parentClasses = $this->owner->stat('parentClass');

        if (!is_array($parentClasses)) {
            return array($parentClasses);
        }

        return $parentClasses;
    }

    /**
     * Gets the fieldname for the sort column. Uses in owner's config for $sort_column
     *
     * @return string
     */
    public function getSortFieldname()
    {
        return $this->owner->config()->get('sort_column');
    }
}
