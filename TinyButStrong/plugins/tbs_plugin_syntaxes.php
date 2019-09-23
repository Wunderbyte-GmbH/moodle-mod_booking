<?php

/**
 *
 * TinyButStrong Plug-in: this is a template plug-in that shows syntaxes for all events
 *
 * @version 1.7
 * @author  Skrol29
 *
 * Chang-log:
 * Version 1.4, on 2008-02-29
 * Version 1.5, on 2010-02-16, Rename argument $HtmlCharSet into $Charset.
 * Version 1.6, on 2011-02-10, Direct commands.
 * Version 1.7, on 2015-11-07, New parameters for OnCacheField(), and PhpDoc syntax.
 */

/** Name of the class is a keyword used for Plug-In authentication. So it's better to save it into a constant. */
define('TBS_THIS_PLUGIN','clsTbsThisPlugIn');

/**
 * Constants for direct commands (direct commands are supported since TBS version 3.6.2)
 * Direct command must be a string which is prefixed by the name of the class followed by a dot (.).
 */
define('TBS_THIS_COMMAND1','clsTbsThisPlugIn.command1');
define('TBS_THIS_COMMAND2','clsTbsThisPlugIn.command1');

/**
 * Put the name of the class into global variable array $_TBS_AutoInstallPlugIns to have it automatically installed for any new TBS instance.
 * Example:
 * $GLOBALS['_TBS_AutoInstallPlugIns'][] = TBS_THIS_PLUGIN;
 */

class clsTbsThisPlugIn {

	/**
	 * Property $this->TBS of the current class is automatically set by TinyButStrong when the Plug-In is installed.
	 * More precisely, it's added after the instantiation  of the plug-in's class, and before the call to method OnInstall().
	 * You can use this property inside all the following methods.
	 */

	/**
	 * Executed when the current plug-in is installed automatically or manually.
	 * You can define additional arguments to this method for the manual installation, but they should be optional in order to have the method compatible with automatic install.
	 * This method must return the list of TBS reserved methods that you want to be activated.
	 * Manual installation:
	 * $TBS->PlugIn(TBS_INSTALL,TBS_THIS_PLUGIN);
	 *  or the first call of:
	 * $TBS->PlugIn(TBS_THIS_PLUGIN);
		$this->Version = '1.00'; // Versions of installed plug-ins can be displayed using [var..tbs_info] since TBS 3.2.0
		$this->DirectCommands = array(TBS_THIS_COMMAND1, TBS_THIS_COMMAND2); // optional, supported since TBS version 3.7.0. Direct Command's ids must be strings.
		return array('OnCommand','BeforeLoadTemplate','AfterLoadTemplate','BeforeShow','AfterShow','OnData','OnFormat','OnOperation','BeforeMergeBlock','OnMergeSection','OnMergeGroup','AfterMergeBlock','OnSpecialVar','OnMergeField');
	}
	 */
	function OnInstall() {
	}
	
	/**
	 * Executed when TBS method PlugIn() is called. Arguments are for your own needs.
	 * You can use as many arguments as you want, but they have to be compatible with your PlugIn() calls.
	 * Example with a non-direct command:  $TBS->PlugIn(TBS_THIS_PLUGIN,$x1,$x2);
	 * Example with a direct command:  $TBS->PlugIn(TBS_THIS_COMMAND1, $x2);
	 */
	function OnCommand($x1,$x2) {
	}

	/**
	 * Executed before a template is loaded. Arguments are those passed to method LoadTemplate().
	 * If you make this method to return value False, then the default LoadTemplate() process is not executed. But AfterLoadTemplate() is checked anyway.
	 * You can define additional arguments to this method in order to extend the syntax of method LoadTemplate().
	 */
	function BeforeLoadTemplate(&$File,&$Charset) {
	}

	/**
	 * Executed after a template is loaded. Arguments are those passed to method LoadTemplate().
	 * The value that you make this method to return is also returned by method LoadTemplate().
	 * You can define additional arguments to this method in order to extend the syntax of method LoadTemplate().
	 */
	function AfterLoadTemplate(&$File,&$Charset) {
	}

	/**
	 * Executed when method Show() is called. Arguments are those passed to method Show().
	 * If you make this method to return value False, then the default Show() process is not executed. But AfterShow() is checked anyway.
	 * You can define additional arguments to this method in order to extend the syntax of method Show().
	 */
	function BeforeShow(&$Render) {
	}

	/**
	 * Executed at the end of method Show(). Arguments are those passed to method Show().
	 * Output and exit are processed after this event but you can cancel any of them using the argument $Render.
	 * The value that you make this method to return is also returned by method Show().
	 * You can define additional arguments to this method in order to extend the syntax of method Show().
	 */
	function AfterShow(&$Render) {
	}

	/**
	 * Executed during MergeBlock(), when TBS retrieve a record for merging.
	 * This event has the same behavior as parameter "ondata", but coded in a plug-in.
	 * Please note that this event is executed only once over the data source even they are several blocks to merge with it. 
	 *
	 * @param string  $BlockName Name of the block currently merged.
	 * @param array   $CurrRec   (read/write) current record.
	 * @param integer $RecNum    (read only) number of the current record (first is number 1).
	 * @param object  $TBS       Extra argument for coherence with parameter 'ondata'.
	 */
	function OnData($BlockName,&$CurrRec,$RecNum,&$TBS) {
	}

	/**
	 * Executed each time an item value is merged to the template, so use it only if needed.
	 * If you want to supply additional parameters to TBS, it's better to use the method OnOperation.  
	 *
	 * @param string $FieldName  Name of the field currently merged.
	 * @param mixed  $Value      Value about to be merged, before the string conversion if any.
	 * @param array  $PrmLst     Array of the field's parameters.
	 * @param object $TBS        Extra argument for coherence with parameter 'onformat'.
	 */
	function OnFormat($FieldName,&$Value,&$PrmLst,&$TBS) {
	}

	/**
	 * Executed each time a field contains parameter 'ope' with an unsupported keyword.
	 * If the function returns false, then the TBS default merging is canceled.
	 * This can be useful when you want to customize parameter 'ope' to proceed your own merging.
	 *
	 * @param string  $FieldName  Name of the field currently merged.
	 * @param mixed   $Value      (read/write) value about to be merged, before the string conversion if any.
	 * @param array   $PrmLst     The array of the field's parameters. We know that parameter 'ope' is set.
	 * @param string  $Txt        Optional. Undocumented.
	 * @param integer $PosBeg     Optional. Undocumented.
	 * @param integer $PosEnd     Optional. Undocumented.
	 * @param objet   $Loc        Optional. Undocumented.
	 *
	 * @return mixed If the function returns false, then the TBS default merging is canceled.
	 *
	 */
	function OnOperation($FieldName,&$Value,&$PrmLst,&$Txt,$PosBeg,$PosEnd,&$Loc) {
	}

	/**
	 * Executed each time a TBS field is found during the block analysis and about to be cached.
	 *
	 * No merging is processed here. But parameter att is processed just after event OnCacheField() occurs on the field.
	 * This event is supported since TBS 3.6.0.
	 *
	 * @param string $BlockName Name of the block currently merged
	 * @param object $Loc       The TBS field object just found
	 * @param string $Txt       Undocumented
	 * @param array  $PrmProc   An array that contains parameters that will be processed before the field is cached (depends only of the TBS version)
	 * @param array  $LocLst    (Read/Write) The array of the locators already cached. (optional, supported since TBS 3.10.0)
	 *                          Can be used to move, delete or add locators.
	 *                          Move: backward or forward ; Delete: add property « DelMe=true » to the locator ; Add: must be at the end of $LocLst, it will be reindexed by TBS.
	 * @param array  $Pos       (Read/Write) The position of the search for the next locator. (optional, supported since TBS 3.10.0)
	 */
	function OnCacheField($BlockName,&$Loc,&$Txt,$PrmProc,&$LocLst,&$Pos) {
	}

	/**
	 * Executed each time a named block is found, analyzed and ready for merging.
	 *
	 * @param string  $TplSource Source of the current template.
	 * @param integer $BlockBeg  Position the begining of the block in $TplSource.
	 * @param integer $BlockEnd  Position the end      of the block in $TplSource.
	 * @param array   $PrmLst    (Read only) The array of the block's parameters.
	 * @param string  $DataSrc   Optional. Undocumented.
	 * @param object  $LocR      Optional. Undocumented. (supported since TBS 3.0.5)	
	 */
	function BeforeMergeBlock(&$TplSource,&$BlockBeg,&$BlockEnd,$PrmLst,&$DataSrc,&$LocR) {

	}

	/**
	 * Executed before a merged section is added to the block's buffer.
	 */
	function OnMergeSection(&$Buffer,&$NewPart) {
	}

	/**
	 * Excuted before a header, a footer or a splitter section is merged. (supported since TBS 3.3.0)
	 * If the function returns False, then the section of the group is not merged.
	 *
	 * @param object $RecInfo  An object having properties CurrRec (current record read/write), RecNum and RecKey.
	 * @param object $GrpDef   An object having property Type and others undocumented. 
	 * @param string $DataSrc  Optional. Undocumented.
	 * @param object $LocR     Optional. Undocumented.
	 */
	function OnMergeGroup(&$RecInfo,&$GrpDef,&$DataSrc,&$LocR) {
	}

	/**
	 * Executed each time a named block is merged but not yet inserted to the template.
	 *
	 * @param string $Buffer   Merged block contents to insert into the template.
	 * @param string $DataSrc  Optional. Undocumented. (supported since TBS 3.0.5)
	 * @param object $LocR     Optional. Undocumented. (supported since TBS 3.0.5)
	 */
	function AfterMergeBlock(&$Buffer,&$DataSrc,&$LocR) {
	}

	/**
	 * Executed when an unsupported Special Var field ([var..*]) is met before TBS try to merge it.
	 * This enables you define customized Special Var fields.
	 *
	 * @param string  $Name         (Read only)  The name of the current Special Var field. 
	 * @param boolean $IsSupported  (Read/Write) Set this argument to true to indicates that the plug-in supports the field, otherwise TBS will raise an error for unsupported Special Var field.
	 * @param mixed   $Value        (Read/Write) Value of the field (empty string by default).
	 * @param array   $PrmLst       (Read/Write) The array of the field's parameters.
     * Extended syntax can delcare the followings:
	 * @param string  $Source       (Read/Write) Current contents of the merged template; 
	 * @param integer $PosBeg       (Read/Write) Position of the first char of the current field in $Source. If this value is set to false, then TBS doesn't merge the field itself. In this case, $PosEnd must be set to the position where TBS must continue the merge.
	 * @param integer $PosEnd       (Read/Write) Position of the last char of the current field in $Source.
	 * @param integer $Loc          Optional. Undocumented.
	 *
	*/
	function OnSpecialVar($Name,&$IsSupported,&$Value,&$PrmLst,&$Source,&$PosBeg,&$PosEnd,&$Loc) {
	}

	/**
	 * Executed each time a field is merged using the MergeField() method.
	 * If the function return False, then TBS won't merge the field assuming that it has been done by the current plug-in event
	 */
	function OnMergeField($AskedName,$SubName,&$Value,&$PrmLst,&$Source,&$PosBeg,&$PosEnd) {
    }

}
