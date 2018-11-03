#!/usr/bin/php
<?PHP

chdir ( '/data/project/commons-delinquent' ) ;

error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR); # E_ALL|
ini_set('display_errors', 'On');

require_once ( './shared.inc' ) ;

class CommonsDelinquentDemon extends CommonsDelinquent {

	var $avoidNamespaceOnWiki = [
		'dewiki' => [4]
	] ;

	var $delay_minutes = 10 ;  # Wait after deletion
	var $fallback_minutes = 120 ; # Only used if DB is empty
	var $max_text_diff = 1500 ; # Max char diff
	var $min_faux_template_icon = 500 ;
	var $comments = array() ;
	var $comments_default = array (
		'summary' => 'Removing [[:c:File:$1|$1]], it has been deleted from Commons by [[:c:User:$2|$2]] because: $3.' ,
		'replace' => 'Replacing $1 with [[File:$2]] (by [[:c:User:$3|$3]] because: $4).' ,
		'by' => ' Requested by [[User:$1|]].'
	) ;
	

	// Returns the last timestamp in the tool database, or a dummy (current time - X min)
	function getLastTimestamp () {
		# Open tool database
		$db = $this->getToolDB() ;
		
		# Get highest timestamp in tool DB as a starting point
		$max_ts = '' ;
		$sql = "SELECT max(log_timestamp) AS max_ts FROM event WHERE done=1" ; # Timestamp of Commons logging table, NOT tool edit timestamp!
		$result = $this->runQuery ( $db , $sql ) ;
		while($o = $result->fetch_object()){
			$max_ts = $o->max_ts ;
		}
		$db->close() ;
		if ( $max_ts == '' ) $max_ts = date ( 'YmdHis' , time() - $this->fallback_minutes*60 ) ; # Fallback to current date minus X min
		return $max_ts ;
	}
	
	function isBadPage ( $o , $filename ) {
		if ( $o->gil_page_namespace_id == 6 and $o->gil_wiki == 'commonswiki' and $o->gil_to == $filename ) return true ; // Self-reference
		if ( $o->gil_page_namespace_id == 2 and $o->gil_wiki == 'commonswiki' and preg_match ( '/^\w+Bot\b/' , $o->gil_page_title ) ) return true ; // Bot subpage on Commons
		if ( $o->gil_page_namespace_id == 4 and $o->gil_wiki == 'commonswiki' and preg_match ( '/(Deletion(_| )requests\/.*|Undeletion(_| )requests\/.*)\b/' , $o->gil_page_title ) ) return true ; // DR and UDR on Commons
		foreach ( $this->avoidNamespaceOnWiki AS $wiki => $namespaces ) {
			if ( $o->gil_wiki != $wiki ) continue ;
			foreach ( $namespaces AS $namespace ) {
				if ( $namespace == $o->gil_page_namespace_id ) return true ;
			}
		}
		return false ;
	}
	
	function getRecentDeletedFiles ( $max_ts ) {
		# Open Commons database replica
		$db_co = $this->getCommonsDB() ;
		$cur_ts = date ( 'YmdHis' , time() - $this->delay_minutes*60 ) ;

		# Get all file deletions
		$delink_files = array() ; # Files to delink
		$sql = "SELECT * FROM logging LEFT JOIN comment ON comment_id = log_comment_id WHERE log_type='delete' AND log_action='delete' AND log_timestamp>='$max_ts' AND log_timestamp<'$cur_ts' AND log_namespace=6" ;
		$sql .= " AND NOT EXISTS (SELECT * FROM image WHERE img_name=log_title)" ;
		$sql .= " AND NOT EXISTS (SELECT * FROM page WHERE page_title=log_title AND page_namespace=6 AND page_is_redirect=1)" ; # Do not remove redirects. Is that OK???
		$sql .= " ORDER BY log_timestamp ASC" ;
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
				if ( $this->isBadWiki($o->gil_wiki) ) continue ;
				if ( $this->isBadPage($o,$filename) ) continue ;
				$deletion->usage[] = $o ;
			}
		}
		$db_co->close() ;
//		print_r ( $delink_files ) ;
		return $delink_files ;
	}

	function getFileUsage ( $filename ) {
		$ret = array() ;
		$db_co = $this->getCommonsDB() ;
		$cur_ts = date ( 'YmdHis' , time() - $this->delay_minutes*60 ) ;
		$sql = "SELECT * FROM globalimagelinks WHERE gil_to='" . $this->getDBsafe($filename) . "'" ;
		$result = $this->runQuery ( $db_co , $sql ) ;
		while($o = $result->fetch_object()){
			if ( $this->isBadWiki($o->gil_wiki) ) continue ;
			$ret[] = $o ;
		}
		$db_co->close() ;
		return $ret ;
	}
	
	function canUnlinkFromNamespace ( $usage ) {
		if ( $usage->gil_page_namespace_id % 2 > 0 ) return false ; // Skip talk pages
		if ( $usage->gil_page_namespace_id < 0 ) return false ; // Paranoia
		return true ;
	}

	function fileExistenceSanityCheck ( $e , $check_commons ) {
		if ( $this->hasLocalFile ( $e->wiki , $e->file ) ) {
			$this->setDone ( $e->id , 2 , 'Skipped: Local file exists' ) ;
			return false ;
		}
		if ( $check_commons and $this->hasLocalFile ( 'commonswiki' , $e->file ) ) {
			$this->setDone ( $e->id , 2 , 'Skipped: Commons file exists' ) ;
			return false ;
		}
		return true ;
	}


	function getTextFromWiki ( $wiki , $pagename ) {
		$ret = false ;
		$api = $this->getAPI ( $wiki ) ;
		if ( $api ) {
			$services = new \Mediawiki\Api\MediawikiFactory( $api );
			$page = $services->newPageGetter()->getFromTitle( $pagename );
			$revision = $page->getRevisions()->getLatest();
		
			if ( $revision ) {
				$ret = $revision->getContent()->getData() ;
			}
		}
		return $ret ;
	}
	
	/**
		mode	"summary" or "replace"
	*/
	function getLocalizedCommentPattern ( $wiki , $mode ) {
		if ( !isset($mode) ) $mode = 'summary' ;
		if ( isset ( $this->comments[$mode][$wiki] ) ) return $this->comments[$mode][$wiki] ;
		$pattern = $this->comments_default[$mode] ; # Default
		
		# Try local translation
		$local = $this->getTextFromWiki ( $wiki , 'User:CommonsDelinker/' . $mode . '-I18n' ) ;
		if ( $local !== false ) $pattern = $local ;
		
		$this->comments[$mode][$wiki] = $pattern ;
		return $pattern ;
	}
	
	function constructUnlinkComment ( $file , $usage ) {
		$pattern = $this->getLocalizedCommentPattern ( $usage->gil_wiki ) ;
		
		$c = $file->comment_text ;
		if ( $usage->wiki != 'commonswiki' ) { # Point original comment links to Commons
			$c = preg_replace ( '/\[\[([^|]+?)\]\]/' , '[[:c:\1|]]' , $c ) ; # Pointing to Commons (no pipe)
			$c = preg_replace ( '/\[\[([^:].+?)\]\]/' , '[[:c:\1]]' , $c ) ; # Pointing to Commons (with pipe)
		}

		$pattern = preg_replace ( '/\$1/' , $file->log_title , $pattern ) ;
		$pattern = preg_replace ( '/\$2/' , $file->log_user_text , $pattern ) ;
		$pattern = preg_replace ( '/\$3/' , $c , $pattern ) ;
#		print "\n$pattern\n" ; exit ( 0 ) ; // TESTING
		return $pattern ;
	}

	function constructReplaceComment ( $params ) {
		$pattern = $this->getLocalizedCommentPattern ( $params['wiki'] , 'replace' ) ;
		
		$c = $params['comment'] ;
		if ( $params['wiki'] != 'commonswiki' ) { # Point original comment links to Commons
			$c = preg_replace ( '/\[\[([^|]+?)\]\]/' , '[[:c:\1|]]' , $c ) ; # Pointing to Commons (no pipe)
			$c = preg_replace ( '/\[\[([^:].+?)\]\]/' , '[[:c:\1]]' , $c ) ; # Pointing to Commons (with pipe)
		}

		$pattern = preg_replace ( '/\$1/' , $params['file'] , $pattern ) ;
		$pattern = preg_replace ( '/\$2/' , $params['replace_with_file'] , $pattern ) ;
		$pattern = preg_replace ( '/\$3/' , 'CommonsDelinker' , $pattern ) ;
		$pattern = preg_replace ( '/\$4/' , $c , $pattern ) ;
		
		if ( isset($params['user']) and $params['user'] != '' ) {
			$by = $this->getLocalizedCommentPattern ( $params['wiki'] , 'by' ) ;
			$by = preg_replace ( '/\$1/' , $params['user'] , $by ) ;
			$pattern .= ' ' . $by ;
		}
		
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
			'comment' => $this->constructUnlinkComment ( $file , $usage ) ,
			'timestamp' => date ( 'YmdHis' ) ,
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
	
	function getJSON4Q ( $e ) {
		$q = $e->page ;
		$url = "http://www.wikidata.org/w/api.php?action=wbgetentities&format=json&ids=" . $q ;
		$j = json_decode ( file_get_contents ( $url ) ) ;
		if ( isset ( $j->entities->$q->missing ) ) { # No such item
			$this->setDone ( $e->id , 2 , "No such item $q" ) ;
			return false ;
		}
		if ( !isset ( $j->entities->$q->claims ) ) {
			$this->setDone ( $e->id , 2 , "Did not find " . $e->file . " on " . $q ) ;
			return false ;
		}
		return $j ;
	}

	function performEditUnlinkWikidata ( $e ) {
		$j = $this->getJSON4Q ( $e ) ;
		if ( $j === false ) return ;

		$q = $e->page ;
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
			$ok = $this->editWikidata ( 'wbremoveclaims' , array ( 'claim'=>implode('|',$remove) , 'summary' => $e->comment ) ) ;
			if ( !$ok ) return ;
		}
		
		$this->setDone ( $e->id , 1 ) ; # OK!
	}


	function performEditReplaceWikidata ( $e ) {
		$j = $this->getJSON4Q ( $e ) ;
		if ( $j === false ) return ;

		$q = $e->page ;

		$j = $j->entities->$q->claims ;
		$remove = array() ;
		foreach ( $j AS $prop => $claims ) {
			foreach ( $claims AS $c ) {
				if ( $c->type != 'statement' ) continue ;
				if ( $c->mainsnak->datatype != 'commonsMedia' ) continue ;
				if ( str_replace ( ' ' , '_' , ucfirst ( trim ( $c->mainsnak->datavalue->value ) ) ) != $e->file ) continue ;
				$remove[] = array ( $c->id , $prop ) ;
			}
		}
		
		if ( count($remove) > 0 ) {

			# Remove old image entries
			$ids = array() ;
			foreach ( $remove AS $r ) $ids[] = $r[0] ;
			$ok = $this->editWikidata ( 'wbremoveclaims' , array ( 'claim'=>implode('|',$ids) ) ) ;
			if ( !$ok ) {
				print "performEditReplaceWikidata:1 failed\n" ;
				return ;
			}

			# Add new image entries
			foreach ( $remove AS $r ) {
				$params = array(
					'snaktype' => 'value' ,
					'property' => $r[1] ,
					'value' => json_encode(str_replace('_',' ',$e->replace_with_file)) ,
					'entity' => $e->page ,
					'summary' => $e->comment
				) ;

				$ok = $this->editWikidata ( 'wbcreateclaim' , $params ) ;
				if ( !$ok ) {
					print "performEditReplaceWikidata:2 failed\n" ;
					return ;
				}

			}
		} else {
			$this->setDone ( $e->id , 2 , 'File link not found in page' ) ;
			return ;
		}
		
		$this->setDone ( $e->id , 1 ) ; # OK!
	}

	
	
	
	function performEditText ( $e ) {
		$api = $this->getAPI ( $e->wiki ) ;
		if ( $api === false ) {
			$this->setDone ( $e->id , 2 , "Could not connect to API" ) ;
			return ;
		}
		$services = new \Mediawiki\Api\MediawikiFactory( $api );
		try {
			$page = $services->newPageGetter()->getFromTitle( $e->page );
		} catch (Exception $e) {
			$this->setDone ( $e->id , 2 , "Page not found" ) ;
			return ;
		}
		$revision = $page->getRevisions()->getLatest();
		
		
		if ( !$revision ) {
			$this->setDone ( $e->id , 2 , "Latest revision not found" ) ;
			return ;
		}
		
		$rev_id = $revision->getId() ;
		$text = $revision->getContent()->getData() ;
		
		$file = $e->file ;
		$first_letter = substr ( $file , 0 , 1 ) ;
		$pattern = substr ( $file , 1 ) ;
		if ( strtoupper($first_letter) != strtolower($first_letter) ) {
			$first_letter = "[" . strtoupper($first_letter) . strtolower($first_letter) . "]" ;
		} else {
			$first_letter = preg_quote ( $first_letter , '/' ) ; # can be metacharacter
		}
		$pattern = str_replace ( '_' , ' ' , $pattern ) ;
		$pattern = $first_letter . preg_quote ( $pattern, '/' ) ;
		$pattern = str_replace ( ' ' , '[_ ]' , $pattern ) ;
		
		$new_text = $text ;

		if ( $e->action == 'unlink' ) {
			$pattern_file= "\b\w+:$pattern\b" ; # e.g. File:x.jog
			$pattern_link = "\[\[\s*$pattern_file(\[\[.*?\]\]|\[.*?\]|.*?)*\]\]" ;
			$pattern_gallery = "^\s*$pattern_file.*$" ;
			$pattern_gallery2 = "^\s*$pattern\s*\|.*$" ;
		
			$new_text = preg_replace ( "/ *$pattern_link */u" , '' , $new_text ) ;
			$new_text = preg_replace ( "/$pattern_gallery/u" , '' , $new_text ) ;
			$new_text = preg_replace ( "/$pattern_gallery2/u" , '' , $new_text ) ;
			$new_text = preg_replace ( "/ *$pattern_file */u" , '' , $new_text ) ;
			$new_text = preg_replace ( "/= *\b$pattern\b */u" , '=' , $new_text ) ;
		} else if ( $e->action == 'replace' ) {
			$new_file = ucfirst ( trim ( str_replace ( '_' , ' ' , $e->replace_with_file ) ) ) ;
			$new_text = preg_replace ( "/\b$pattern\b/" , $new_file , $new_text ) ;
		}
		
		if ( $text == $new_text ) { # No change
			$this->setDone ( $e->id , 2 , 'File link not found in page' ) ;
			return ;
		}
		
		if ( strlen(trim($new_text)) == 0 or abs(strlen($text)-strlen($new_text)) > $this->max_text_diff ) {
			$this->setDone ( $e->id , 2 , 'Text change too big' ) ;
			return ;
		}
		
		if ( !isset($e->comment) ) $e->comment = '' ;
		$e->comment = (string)$e->comment ;

		print "Editing " . $e->wiki . ":" . $e->page . " to " . $e->action . " " . $e->file . " AS " . $e->comment . "\n" ;
		
		
		$params = array (
			'title' => $e->page ,
			'text' => trim($new_text) ,
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

	}
	
	function performEditReplace ( $e ) {
		if ( !$this->fileExistenceSanityCheck($e,false) ) return ; # Nothing to do
		if ( !isset($e->namespace) ) return ; # Paranoia
		if ( $e->wiki == 'wikidatawiki' && $e->namespace == 0 ) { # Wikidata item
			$this->performEditReplaceWikidata ( $e ) ;
		} else { # "Normal" edit
			$this->performEditText ( $e ) ;
		}
	}
	
	function performEditUnlink ( $e ) {
		if ( !$this->fileExistenceSanityCheck($e,true) ) return ; # Nothing to do
		if ( $e->wiki == 'wikidatawiki' && $e->namespace == 0 ) { # Wikidata item
			$this->performEditUnlinkWikidata ( $e ) ;
		} else { # "Normal" edit
			$this->performEditText ( $e ) ;
		}
	}
	
	function performEdit ( $e ) {
		if ( $e->action == 'unlink' ) $this->performEditUnlink ( $e ) ;
		else if ( $e->action == 'replace' ) $this->performEditReplace ( $e ) ;
		else {
			print_r ( $e ) ;
			die ( "Unknown action " . $e->action ) ;
		}
	}
	
	function clearBogusIssues ( $db ) {
		# Clear some previous issues
		$sql = "update `event` set done=0,note='' where note like '%rate limit%' and done=2" ;
		$this->runQuery ( $db , $sql ) ;
		$sql = "update `event` set done=0,note='' where note like '%edit conflict%' and done=2" ;
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
	
	function addReplaceEvents () {
		$cmd_page = 'User:CommonsDelinker/commands' ;
		$t = $this->getTextFromWiki ( 'commonswiki' , $cmd_page ) ;
		if ( $t === false ) {
			print "Could not open commands page\n" ;
			return ;
		}
		
		if ( preg_match ( '/\{\{[Ss]top\}\}/' , $t ) ) return ; // STOP
		
		$sqls = array() ;
		
#		$t = "{{/front}}\n{{universal replace|Overzicht - Hulst - 20118655 - RCE.jpg|Red Weaver Ant, Oecophylla smaragdina.jpg|reason=Testing}}" ; # TESTING
		
		$ts = date ( 'YmdHis' ) ;
		$t = explode ( "\n" , $t ) ;
		$nt = array() ;
		foreach ( $t AS $l ) {
			if ( !preg_match ( '/^\s*\{\{\s*[Uu]niversal[ _]replace\s*\|\s*(.+?)\s*\|\s*(.+?)\s*\|\s*reason\s*=\s*(\S.*?)\s*\}\}/' , $l , $m ) ) {
				if ( !preg_match ( '/^\s*\{\{\s*[Uu]niversal[ _]replace\s*\|\s*(.+?)\s*\|\s*(.+?)\s*\|\s*reason\s*=\s*(.+?)\s*\|\s*user\s*=\s*(\S.*?)\s*\}\}/' , $l , $m ) ) {
					$nt[] = $l ;
					continue ;
				}
			}
			$old_file = ucfirst(str_replace(' ','_',trim($m[1]))) ;
			$new_file = ucfirst(str_replace(' ','_',trim($m[2]))) ;
			$comment = trim($m[3]) ;
			$user = '' ;
			if ( isset($m[4]) ) {
				$user = str_replace(' ','_',trim($m[4])) ;
				$user = preg_replace ( '/^\s*\[\[[^:]+(.+?)\s*(\||\]\]).*$/' , '$1' , $user ) ;
			}
			
			if ( !$this->hasLocalFile ( 'commonswiki' , $new_file ) ) {
				$nt[] = "No such replacement file: " . $l ;
				continue ;
			}

			if ( !preg_match('/\.svg$/i',$old_file) and preg_match('/\.svg$/i',$new_file) ) {
				$nt[] = "Non-SVG to SVG replacement: " . $l ;
				continue ;
			}

			$usages = $this->getFileUsage ( $old_file ) ;
			
			$db = $this->getToolDB() ;
			
			foreach ( $usages AS $usage ) {
				$page = $usage->gil_page_title ;
				if ( $usage->gil_page_namespace != '' ) $page = $usage->gil_page_namespace . ':' . $page ;
				$params = array (
					'action' => 'replace' ,
					'file' => $old_file ,
					'wiki' => $usage->gil_wiki ,
					'page' => $page ,
					'namespace' => $usage->gil_page_namespace_id ,
					'timestamp' => $ts ,
					'comment' => $comment ,
					'log_id' => -1 ,
					'log_timestamp' => $ts ,
					'user' => $user ,
					'done' => 0 ,
					'replace_with_file' => $new_file
				) ;
				$params['comment'] = $this->constructReplaceComment ( $params ) ;
//				print_r ( $params ) ;

				$s1 = array() ;
				$s2 = array() ;
				foreach ( $params AS $k => $v ) {
					$s1[] = $k ;
					$s2[] = "'" . $this->getDBsafe($v) . "'" ;
				}
		
				$sql = "INSERT IGNORE INTO event (" . implode ( ',' , $s1 ) . ") VALUES (" . implode ( "," , $s2 ) . ")" ;
				$sqls[] = $sql ;

			}
			
			$db->close() ;
			
		}
		
		$t = implode ( "\n" , $t ) ;
		$nt = implode ( "\n" , $nt ) ;
		if ( $t == $nt ) return ; // No change
		
		# Run SQL
		$db = $this->getToolDB() ;
		foreach ( $sqls AS $sql ) $this->runQuery ( $db , $sql ) ;
		$db->close() ;
		
		# Save new text to Wiki
		$params = array (
			'title' => $cmd_page ,
			'text' => trim($nt) ,
			'summary' => 'Removing replace commands, will be executed soon' ,
			'bot' => 1
		) ;
		
		print "Editing $cmd_page...\n" ;
		$x = $this->editWiki ( 'commonswiki' , 'edit' , $params ) ;
		print "Editing $cmd_page done.\n" ;
	}
	
	function fixFauxTemplateReplacements () {
		$todo = array() ;
		$db = $this->getToolDB() ;
		$sql = "DELETE FROM event WHERE action='' and file=''" ;
		$result = $this->runQuery ( $db , $sql ) ;
		$sql = 'select file,wiki, count(*) as cnt,namespace from event where done=0 group by file,wiki,namespace having cnt>' . $this->min_faux_template_icon ;
		$result = $this->runQuery ( $db , $sql ) ;
		while($o = $result->fetch_object()){
			$file = $this->getDBsafe ( $o->file ) ;
			$wiki = $this->getDBsafe ( $o->wiki ) ;
			$todo[] = "UPDATE event SET done=2,note='Likely template icon, skipping' WHERE file='$file' AND wiki='$wiki' AND namespace=" . $o->namespace ;
		}
		foreach ( $todo AS $sql ) {
			$this->runQuery ( $db , $sql ) ;
		}
		$db->close() ;
	}

	// Unlinks deleted files
	function run () {
		$max_ts = $this->getLastTimestamp() ;
		$delink_files = $this->getRecentDeletedFiles ( $max_ts ) ;
		$this->addUnlinkEvents ( $delink_files ) ;
		$this->addReplaceEvents () ;
		$this->fixFauxTemplateReplacements() ;
		$this->performEdits() ;
	}

}

$demon = new CommonsDelinquentDemon ;

//$demon->addReplaceEvents () ;
//$demon->performEdits() ;
//$demon->fixFauxTemplateReplacements() ;

$demon->performEdits() ;
while ( 1 ) {
	$demon->run() ;
	sleep ( 30 ) ;
}

?>
