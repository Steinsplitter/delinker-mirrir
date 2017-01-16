<?PHP

error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR); // E_ALL|
ini_set('display_errors', 'On');

require_once ( '../shared.inc' ) ;

$image = trim ( get_request ( 'image' , '' ) ) ;
$action = get_request ( 'action' , 'any' ) ;
$result = get_request ( 'result' , 'any' ) ;

function esc ( $s ) {
        return str_replace ( '"' , '&quot' , str_replace ( "'" , '&#39;' , $s ) ) ;
}

$status = array (
        0 => "<span tt='pending' style='color:black'>Pending</span>" ,
        1 => "<span tt='done' style='color:green'>Done</span>" ,
        2 => "<span tt='skipped' style='color:red'>Skipped</span>"
) ;

$cd = new CommonsDelinquent() ;
$db = $cd->getToolDB() ;
if($_GET["cnt"] == "true"){
$sql = "select count(*) as cnt from  event where done=0" ;
$result = $cd->runQuery ( $db , $sql ) ;
while($o = $result->fetch_object()) $pending = $o->cnt ;
}
print "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">
<html xmlns=\"http://www.w3.org/1999/xhtml\" xml:lang=\"en\" lang=\"en\">
<head>
        <meta http-equiv=\"Content-type\" content=\"text/html;charset=UTF-8\" />
        <title>Commons Delinquent | Commons Delinker</title>
        <link href=\"//tools-static.wmflabs.org/cdnjs/ajax/libs/twitter-bootstrap/3.3.6/css/bootstrap.min.css\" rel=\"stylesheet\">
        <script src=\"//tools-static.wmflabs.org/tooltranslate/tt.js\"></script>
        <script src=\"//tools-static.wmflabs.org/cdnjs/ajax/libs/jquery/2.2.0/jquery.min.js\"></script>
  <style>
    body {
      padding-top: 60px;
    }
  </style>
</head>
<body>
  <div class=\"navbar navbar-default navbar-fixed-top\">
   <div class=\"container-fluid\">
      <div class=\"navbar-header\">
        <a class=\"navbar-brand\" href=\"index.php\"><span tt=\"delinquent\">Commons Delinquent</span></a>
      </div>
        <ul class=\"nav navbar-nav navbar-right\">
          <li><a href=\"//tools.wmflabs.org/commons-delinquent/?image=&action=null&result=null&cnt=true\"><span class=\"glyphicon glyphicon-road\"></span> <span tt=\"pendingedits\">pending edits</span></a></li>
          <li><a href=\"//tools.wmflabs.org/commons-delinquent/?image=&action=null&result=null&status=true\"><span class=\"glyphicon glyphicon-eye-open\"></span> <span tt=\"status\">status</span></a></li>
          <li><a href=\"//bitbucket.org/magnusmanske/commons-delinquent/src\"><span class=\"glyphicon glyphicon-wrench\"></span> <span tt=\"code\">code</span></a></li>
          <li class=\"nav-item\" style = \"padding-top: 5px;\" id=\"tooltranslate_wrapper\"></li>
        </ul>
    </div>
  </div>";
require_once ( "/data/project/tooltranslate/public_html/tt.php") ;
$tt = new ToolTranslation ( array ( 'tool' => 'delinquent' , 'language' => 'en' , 'highlight_missing' => false ) ) ;
print $tt->getJS('#tooltranslate_wrapper') ;
print $tt->getJS() ;
print "<div class=\"container\">
";
print "<a href='//commons.wikimedia.org/wiki/File:CommonsDelinker.svg' class='image' style = 'float:right;'><img alt='CommonsDelinker.svg' src='//upload.wikimedia.org/wikipedia/commons/thumb/d/da/CommonsDelinker.svg/40px-CommonsDelinker.svg.png' srcset='//upload.wikimedia.org/wikipedia/commons/thumb/d/da/CommonsDelinker.svg/60px-CommonsDelinker.svg.png 1.5x, //upload.wikimedia.org/wikipedia/commons/thumb/d/da/CommonsDelinker.svg/80px-CommonsDelinker.svg.png 2x' data-file-width='300' data-file-height='400' height='53' width='40'></a>";
print "<div class='lead'><span tt=\"intro1\">Commons Delinquent is a rewrite of <a href='/delinker'>CommonsDelinker</a>.
It finds files that were deleted on Commons, and removed their entries on other wikis to avoid ugly media redlinks.</span>
<small><br><span tt=\"intro2\">To replace files globally, see <a href='https://commons.wikimedia.org/wiki/User:CommonsDelinker/commands'>this page</a>.</span></small>";
if($_GET["cnt"] == "true"){
print "<br><br><p class='alert alert-info'><span tt=\"cpe\">Currenty pending edits:</span> $pending</p>";
print "<a class='btn btn-default' href='index.php' role='button'><span tt=\"home\">Home</span></a>";
print "</div></div>";
exit();
}
if($_GET["status"] == "true"){
$output2 = shell_exec("job -v demon");
$output3 = str_replace("'demon'","",$output2);
if (preg_match('/has been running/', $output2)) {
    print "<br><br><p class='alert alert-success'><big><span tt=\"botrunning\">Bot is running...</span></big><br><small>$output3</small></p>";
} else {
    print "<br><br><p class='alert alert-danger'><big>><span tt=\"botnotrunning\">Bot is not running.</span></big><br>$output3</p>";
}
print "<a class='btn btn-default' href='index.php' role='button'><span tt=\"home\">Home</span></a>";
print "</div></div>";
exit();
}

print "</div>
<div><form method='get'>
<table class='table'>
<tbody>
<tr><th><span tt=\"fn\">File name</span></th><td><input type='text' name='image' class='form-control' style='width:100%' value='" . esc($image) . "' /></td></tr>
<tr><th><span tt=\"action\">Action</span></th><td><select class='form-control' style='width:auto' name='action'>
<option tt=\"any\" value='any' " . ($action=='any'?'selected':'') . ">Any</option>
<option tt=\"unlink\" value='unlink' " . ($action=='unlink'?'selected':'') . ">Unlink</option>
</select></td></tr>
<tr><th>Result</th><td><select class='form-control' style='width:auto' name='result'>
<option tt=\"any\" value='any' " . ($result=='any'?'selected':'') . ">Any</option>
<option tt=\"pending\" value='0' " . ($result=='0'?'selected':'') . ">Pending</option>
<option tt=\"done\" value='1' " . ($result=='1'?'selected':'') . ">Done</option>
<option tt=\"skipped\" value='2' " . ($result=='2'?'selected':'') . ">Skipped</option>
</select></td></tr>
<tr><th></th><td><input type='submit' value='Filter' class='btn btn-primary' /> <a href='?'><span tt=\"reset\">Reset form</span></a></td></tr>
</tbody>
</div>
</table>
</form></div>" ;

$mode = get_request ( 'mode' , 'latest' ) ;


if ( $mode == 'latest' ) {
        $num = get_request ( 'num' , 100 ) * 1 ;
        $offset = get_request ( 'offset' , 0 ) * 1 ;

        $where = array() ;
        if ( $image != '' ) $where[] = "file='" . $cd->getDBsafe(ucfirst(str_replace(' ','_',$image))) . "'" ;
        if ( $action != 'any' ) $where[] = "action='" . $cd->getDBsafe($action) . "'" ;
        if ( $result != 'any' ) $where[] = "done='" . $cd->getDBsafe($result*1) . "'" ;

        $sql = "SELECT * FROM event" ;
        if ( count($where) > 0 ) $sql .= " WHERE " . implode(" AND ",$where) ;
        $sql .= " ORDER BY timestamp DESC,log_timestamp DESC limit $num offset $offset" ;
//      print "<pre>$sql</pre>" ;
        $result = $cd->runQuery ( $db , $sql ) ;
        print "<table class='table table-condensed table-striped'>" ;
        print "<thead><th><span tt=\"time\">Time</span></th><th><span tt=\"file\">File</span></th><th><span tt=\"page\">Page</span></th><th><span tt=\"status\">Status</span></th></thead>" ;
        print "<tbody style='font-size:9pt'>" ;
        while($o = $result->fetch_object()) {
                print "<tr>" ;
                print "<td nowrap>" . substr($o->timestamp,0,4).'-'.substr($o->timestamp,4,2).'-'.substr($o->timestamp,6,2).'&nbsp;'.substr($o->timestamp,8,2).':'.substr($o->timestamp,10,2).':'.substr($o->timestamp,12,2) ;
                if ( $o->action == 'replace' ) print "<br/><i><span tt=\"rep\">Replacing file</span></i>" ;
                print "</td>" ;

                if ( $o->action == 'replace' ) {
                        print "<td><a target='_blank' href='//commons.wikimedia.org/wiki/File:" . htmlspecialchars($o->file) . "'>" . str_replace('_',' ',$o->file) . "</a>" ;
                        print "<br/>&Rarr;<a target='_blank' href='//commons.wikimedia.org/wiki/File:" . htmlspecialchars($o->replace_with_file) . "'>" . str_replace('_',' ',$o->replace_with_file) . "</a></td>" ;
                } else {
                        print "<td><a target='_blank' href='//commons.wikimedia.org/wiki/Special:Log?page=File:" . htmlspecialchars($o->file) . "'>" . str_replace('_',' ',$o->file) . "</a></td>" ;
                }

                print "<td><a target='_blank' title='Bot edits' href='//" . $cd->wiki2server($o->wiki) . "/wiki/Special:Contributions/" . urlencode($cd->config['name']) . "'>" . $o->wiki . "</a>:" ;
                print "<a target='_blank' href='//" . $cd->wiki2server($o->wiki) . "/wiki/" . htmlspecialchars($o->page) . "'>" . str_replace('_',' ',$o->page) . "</a></td>" ;

                print "<td style='width:120px'>" . $status[$o->done] ;
                if ( $o->note != '' ) print "<br/><small>" . $o->note . "</small>" ;
                print "</td>" ;
                print "</tr>" ;
        }
        print "</tbody></table>" ;

        print "<div>" ;
        if ( $offset > 0 ) print "<a href='?mode=$mode&num=$num&offset=" . ($offset-$num) . "'><span tt=\"newer\">Newer</span> $num</a> | " ;
        print "<a href='?mode=$mode&num=$num&offset=" . ($offset+$num) . "'><span tt=\"older\">Older</span> $num</a>" ;
        print "</div>" ;

}

print get_common_footer() ;

?>
