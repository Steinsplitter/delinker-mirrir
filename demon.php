#!/usr/bin/php
<?PHP

error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR); # E_ALL|
ini_set('display_errors', 'On');

require_once ( './shared.inc' ) ;

class CommonsDelinquentDemon extends CommonsDelinquent {

	var $delay_minutes = 1 ;  # Wait after deletion
	var $fallback_minutes = 120 ; # Only used if DB is empty

	// Returns the last timestamp in the tool database, or a dummy (current time - X min)
	function getLastTimestamp () {
		# Open tool database
		$db = $this->getToolDB() ;
		
		# TESTING FIXME BEGIN
#		$sql = "TRUNCATE event" ;
#		$result = $this->runQuery ( $db , $sql ) ;
		# TESTING END

		# Get highest timestamp in tool DB as a starting point
		$max_ts = '' ;
		$sql = "SELECT max(log_timestamp) AS max_ts FROM event WHERE done=1" ; # Timestamp of Commons logging table, NOT tool edit timestamp!
		$result = $this->runQuery ( $db , $sql ) ;
		while($o = $result->fetch_object()){
			$max_ts = $o->max_ts ;
		}
		$db->close() ;
		if ( $max_ts == '' ) $max_ts = date ( 'YmdGis' , time() - $this->fallback_minutes*60 ) ; # Fallback to current date minus X min
		return $max_ts ;
	}
	
	function getRecentDeletedFiles ( $max_ts ) {
		# Open Commons database replica
		$db_co = $this->getCommonsDB() ;
		
		$cur_ts = date ( 'YmdGis' , time() - $this->delay_minutes*60 ) ;

		# Get all file deletions
		$delink_files = array() ; # Files to delink
		$sql = "SELECT * FROM logging WHERE log_type='delete' AND log_action='delete' AND log_timestamp>='$max_ts' AND log_timestamp<'$cur_ts' AND log_namespace=6 AND NOT EXISTS (SELECT * FROM image WHERE img_name=log_title) ORDER BY log_timestamp ASC" ;
		$result = $this->runQuery ( $db_co , $sql ) ;
		while($o = $result->fetch_object()){
			$delink_files[] = $o ;
		}
		foreach ( $delink_files AS $deletion ) {
			$filename = $deletion->log_title ;
			$sql = "SELECT * FROM globalimagelinks WHERE gil_to='" . $this->getDBsafe($filename) . "'" ;
			$deletion->usage = array() ; # Usage instances for this file
			$result = $this->runQuery ( $db_co , $sql ) ;
			while($o = $result->fetch_object()){
				$deletion->usage[] = $o ;
			}
		}
		$db_co->close() ;
		return $delink_files ;
	}
	
	function canUnlinkFromNamespace ( $usage ) {
		if ( $usage->gil_page_namespace_id == 2 ) return false ; // Skip user namespace
		if ( $usage->gil_page_namespace_id % 2 > 0 ) return false ; // Skip talk pages
		if ( $usage->gil_page_namespace_id < 0 ) return false ; // Paranoia
		return true ;
	}
	
	function constructUnlinkComment ( $file ) {
		$pattern = 'Removing "$FILE", it has been deleted from Commons by $USER because: $COMMENT' ;
		# TODO get i18n message pattern, if available
		$pattern = preg_replace ( '/\$FILE/' , $file->log_title , $pattern ) ;
		$pattern = preg_replace ( '/\$USER/' , '[[User:'.$file->log_user_text.']]' , $pattern ) ;
		$pattern = preg_replace ( '/\$COMMENT/' , $file->log_comment , $pattern ) ;
		$pattern = preg_replace ( '/\[\[([^|]+?)\]\]/' , '[[:commons:\1|]]' , $pattern ) ; # Pointing to Commons (no pipe)
		$pattern = preg_replace ( '/\[\[([^:].+?)\]\]/' , '[[:commons:\1]]' , $pattern ) ; # Pointing to Commons (with pipe)
		return $pattern ;
	}
	
	function addUnlinkEvent ( $file , $usage , &$sqls ) {
		if ( !$this->canUnlinkFromNamespace ( $usage ) ) return ;
		if ( $this->hasLocalFile ( $usage->gil_wiki , $usage->gil_to ) ) return ;
		
		$page = $usage->gil_page_title ;
		if ( $usage->gil_page_namespace != '' ) $page = $usage->gil_page_namespace . ":$page" ;
		$params = array (
			'action' => 'unlink' ,
			'file' => $usage->gil_to ,
			'wiki' => $usage->gil_wiki ,
			'page' => $page ,
			'namespace' => $usage->gil_page_namespace_id ,
			'comment' => $this->constructUnlinkComment ( $file ) ,
			'timestamp' => date ( 'YmdGis' ) ,
			'log_id' => $file->log_id ,
			'log_timestamp' => $file->log_timestamp ,
			'done' => 0
		) ;
#		print_r ( $params ) ;
		
		$s1 = array() ;
		$s2 = array() ;
		foreach ( $params AS $k => $v ) {
			$s1[] = $k ;
			$s2[] = "'" . $this->getDBsafe($v) . "'" ;
		}
		
		$sql = "INSERT IGNORE INTO event (" . implode ( ',' , $s1 ) . ") VALUES (" . implode ( "," , $s2 ) . ")" ;
		$sqls[] = $sql ;
	}
	
	function addUnlinkEvents ( $delink_files ) {
		$sqls = array() ;
		foreach ( $delink_files AS $file ) {
			foreach ( $file->usage AS $usage ) {
				$this->addUnlinkEvent ( $file , $usage , $sqls ) ;
			}
		}
		
		$db = $this->getToolDB() ;
		foreach ( $sqls AS $sql ) $this->runQuery ( $db , $sql ) ;
		$db->close() ;
	}

	function performEditUnlinkWikidata ( $e ) {

		# TODO check if item still exists
		$q = $e->page ;
		$url = "http://www.wikidata.org/w/api.php?action=wbgetentities&format=json&ids=" . $q ;
		$j = json_decode ( file_get_contents ( $url ) ) ;
		if ( !isset ( $j->entities->$q->claims ) ) {
			$this->setDone ( $e->id , 2 , "Did not find " . $e->file . " on " . $e->page . ", not trying again" ) ; # Fail, but don't try again
			return ;
		}
		$j = $j->entities->$q->claims ;
		$remove = array() ;
		foreach ( $j AS $prop => $claims ) {
			foreach ( $claims AS $c ) {
				if ( $c->type != 'statement' ) continue ;
				if ( $c->mainsnak->datatype != 'commonsMedia' ) continue ;
				if ( str_replace ( ' ' , '_' , ucfirst ( trim ( $c->mainsnak->datavalue->value ) ) ) != $e->file ) continue ;
				$remove[] = $c->id ;
			}
		}
		
		if ( count($remove) > 0 ) {
			$ok = $this->editWikidata ( 'wbremoveclaims' , array ( 'claim'=>implode('|',$remove) ) ) ;
			if ( !$ok ) return ;
		}
		
		$this->setDone ( $e->id , 1 ) ; # OK!
	}
	
	function performEditUnlinkText ( $e ) {
#		if ( $e->wiki != 'enwiki' ) return ; # TESTING
		$api = $this->getAPI ( $e->wiki ) ;
		if ( $api === false ) {
			$this->setDone ( $e->id , 2 , "Could not connect to API" ) ;
			return ;
		}
		$services = new \Mediawiki\Api\MediawikiFactory( $api );
		$page = $services->newPageGetter()->getFromTitle( $e->page );
		$revision = $page->getRevisions()->getLatest();
		
		if ( !$revision ) {
			$this->setDone ( $e->id , 2 , "Latest revision not found" ) ;
			return ;
		}
		
#		print_r ( $revision ) ;
		$rev_id = $revision->getId() ;
		$text = $revision->getContent()->getData() ;
		
		$file = $e->file ;
		$first_letter = substr ( $e->file , 0 , 1 ) ;
		if ( strtoupper($first_letter) != strtolower($first_letter) ) {
			$file = "[" . strtoupper($first_letter) . strtolower($first_letter) . "]" . substr ( $e->file , 1 ) ;
#			print $e->file . " => " . $file . "\n" ; exit ( 0 ) ;
		}
		$pattern = preg_replace ( '/[_ ]/' , '[ _]' , $file ) ;
		$pattern = preg_replace ( '/\./' , '\\.' , $pattern ) ;
//		if ( !preg_match ( "/\b".$pattern."\b/" , $text ) ) return ; # TODO mark this as obsolete in DB
		
		$pattern_file= "\b[a-zA-Z]+:$pattern\b" ; # e.g. File:x.jog
		$pattern_link = "\[\[\s*$pattern_file.*?(\[\[[^\]\[]+?\]\].*?)*\]\]" ;
		$pattern_gallery = "^\s*$pattern_file.*$" ;
		
		$new_text = $text ;
		$new_text = preg_replace ( "/ *$pattern_link */" , '' , $new_text ) ;
		$new_text = preg_replace ( "/$pattern_gallery/" , '' , $new_text ) ;
		$new_text = preg_replace ( "/ *$pattern_file */" , '' , $new_text ) ;
		$new_text = preg_replace ( "/ *\b$pattern\b */" , '' , $new_text ) ;
		
		if ( $text == $new_text ) { # No change
			$this->setDone ( $e->id , 2 , 'File link not found in page' ) ;
			return ;
		}
		
		print "Editing " . $e->wiki . ":" . $e->page . " to unlink " . $e->file . "\n" ;
		
		$params = array (
			'title' => $e->page ,
			'text' => $new_text ,
			'summary' => $e->comment ,
			'bot' => 1
		) ;
		
		$x = $this->editWiki ( $e->wiki , 'edit' , $params ) ;
		if ( $x and $x['edit']['result'] == 'Success' ) {
			$this->setDone ( $e->id , 1 , array('revision'=>$rev_id) ) ;
		} else {
			print "Nope: " . $this->last_exception . "\n" ;
			$this->setDone ( $e->id , 2 , $this->last_exception ) ;
		}

#		$revision->getContent()->setText( $new_text );
#		$services->newRevisionSaver()->save( $revision );
	}
	
	function performEditUnlink ( $e ) {
		if ( $this->hasLocalFile ( $e->wiki , $e->file ) ) {
			$this->setDone ( $e->id , 2 , 'Skipped: Local file exists' ) ;
			return ;
		}
		if ( $this->hasLocalFile ( 'commonswiki' , $e->file ) ) {
			$this->setDone ( $e->id , 2 , 'Skipped: Commons file exists' ) ;
			return ;
		}
		if ( $e->wiki == 'wikidatawiki' && $e->namespace == 0 ) { # Wikidata item
			$this->performEditUnlinkWikidata ( $e ) ;
		} else { # "Normal" edit
			$this->performEditUnlinkText ( $e ) ;
		}
	}
	
	function performEdit ( $e ) {
		if ( $e->action == 'unlink' ) $this->performEditUnlink ( $e ) ;
		else {
			print_r ( $e ) ;
			die ( "Unknown action " . $e->action ) ;
		}
	}
	
	function clearBogusIssues ( $db ) {
		# Clear some previous issues
		$sql = "update `event` set done=0,note='' where note like '%rate limit%'" ;
		$this->runQuery ( $db , $sql ) ;
	}
	
	function performEdits () {
		$edits = array() ;
		$db = $this->getToolDB() ;
		$this->clearBogusIssues ( $db ) ;
		$sql = "SELECT * FROM `event` WHERE done=0 ORDER BY timestamp ASC,log_timestamp ASC" ;
		$result = $this->runQuery ( $db , $sql ) ;
		while($o = $result->fetch_object()){
			$edits[] = $o ;
		}
		$db->close() ;

		$last_wiki = '' ;
		foreach ( $edits AS $o ) {
			if ( $last_wiki == $o->wiki ) sleep ( 5 ) ; // Edit rate limiter
			$this->performEdit ( $o ) ;
			$last_wiki = $o->wiki ;
		}

		$db = $this->getToolDB() ;
		$this->clearBogusIssues ( $db ) ;
		$db->close() ;
	}

	// Unlinks deleted files
	function run () {

		$max_ts = $this->getLastTimestamp() ;
		$delink_files = $this->getRecentDeletedFiles ( $max_ts ) ;
		$this->addUnlinkEvents ( $delink_files ) ;

		$this->performEdits() ;
	}

}

$demon = new CommonsDelinquentDemon ;
$demon->run() ;

?>