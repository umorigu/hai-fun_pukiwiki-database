<?php
// PukiWiki - Yet another WikiWikiWeb clone.
// $Id: index.php,v 1.5 2005/04/29 09:47:40 henoheno Exp $
// Copywrite (C) 2004-2005 PukiWiki Developers Team
// License: GPL v2 or (at your option) any later version

/////////////////////////////////////////////////
// Error reporting

// error_reporting(0): // Nothing
error_reporting(E_ERROR | E_PARSE); // Avoid E_WARNING, E_NOTICE, etc
// error_reporting(E_ALL);

/////////////////////////////////////////////////
// Directory definition
// (Ended with a slash like '../path/to/pkwk/', or '')
define('DATA_HOME',	'');
define('LIB_DIR',	'lib/');

/////////////////////////////////////////////////
require(LIB_DIR . 'pukiwiki.php');
?>
