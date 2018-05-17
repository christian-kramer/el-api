<?php

include "security.php";

error_reporting(E_ALL); ini_set('display_errors', 1);

$basedir = "../../../data/evanslarson.com/app";

class TVEyes
{
    public $basedir;
    private $username;
    private $password;
    private $session;

    function __construct()
    {
        $this->username = "";
        $this->password = "";
        global $basedir;
        $this->basedir = $basedir;

        $this->connect();
    }

    function connect()
    {
        $fields = array(
            'address' => $this->username,
            'Password' => $this->password
        );

        foreach ($fields as $key=>$value)
        {
            $fields_string .= $key.'='.$value.'&';
        }
        rtrim($fields_string, '&');


        $this->session = curl_init();
        curl_setopt($this->session, CURLOPT_URL, 'https://mms.tveyes.com/scripts/LogIn2.asp');
        curl_setopt($this->session, CURLOPT_SSL_VERIFYPEER,0);       // Allow self-signed certs
        curl_setopt($this->session, CURLOPT_SSL_VERIFYHOST,0);       // Allow certs that do not match the hostname
        curl_setopt($this->session, CURLOPT_POST, true);
        curl_setopt($this->session, CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($this->session, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->session, CURLOPT_COOKIESESSION, true);
        curl_setopt($this->session, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($this->session, CURLOPT_COOKIEJAR, '../../../data/evanslarson.com/app/cookiejar/tveyes/tveyes.txt');
        $answer = curl_exec($this->session);
        if (curl_error($this->session)) {
            echo curl_error($this->session);
        }
    }

    function collect()
    {
        set_time_limit(0);

        curl_setopt($this->session, CURLOPT_URL, 'https://mms.tveyes.com/NetReport.aspx');
        curl_setopt($this->session, CURLOPT_POST, false);
        curl_setopt($this->session, CURLOPT_POSTFIELDS, "");
        $answer = curl_exec($this->session);
        if (curl_error($this->session)) {
            echo curl_error($this->session);
        }

        $dom = new DOMDocument();
        $dom->loadHTML($answer);
        $xpath = new DomXPath($dom);

        $options = $xpath->query("//option");
        $values = array();

        if (count($options))
        {
            foreach ($options as $option)
            {
                $value = $option->getAttribute('value');
                $checkdate = substr($option->nodeValue, 0, 6);
                $folder = substr($option->nodeValue, 7);
                if ($value && is_numeric($checkdate))
                {                    
                    $reports = array_map('str_getcsv', file("https://mms.tveyes.com/NetReport.aspx?ReportHash=$value&Action=getcsv&SORT=DATE_A"));
                    array_walk($reports, function(&$a) use ($reports) {
                        $headers = array();
                        foreach ($reports[0] as $header)
                        {
                            $headers[] = trim($header);
                        }
                        $values = array();
                        foreach ($a as $value)
                        {
                            $values[] = trim($value);
                        }
                        $a = array_combine($headers, $values);
                    });
                    array_shift($reports);
                    
                    foreach ($reports as $report)
                    {
                        $date = DateTime::createFromFormat('n/d/Y h:i:s a', $report['Date']);
                        $serialmonth = $date->format('Ym');

                        $data = json_encode($report);

                        $hash = md5($data);

                        mkdir("$this->basedir/staging/tveyes/$folder/$serialmonth", 0755, TRUE);
                        file_put_contents("$this->basedir/staging/tveyes/$folder/$serialmonth/$hash.json", $data);
                        var_dump($report);
                    }
                }
            }
        }
    }
}

class Mention
{
    private $accountid;
    private $token;
    public $basedir;

    function __construct()
    {
        $this->accountid = '';
        $this->token = '';
        global $basedir;
        $this->basedir = $basedir;
    }

    function collect()
    {
        set_time_limit(0);

        $opts = [
            "http" => [
                "method" => "GET",
                "header" => "Accept: application/json",
                "header" => "Accept-Language: en",
                "header" => "Authorization: Bearer $this->token"
            ]
        ];
        
        $context = stream_context_create($opts);

        $alerts = json_decode(file_get_contents("https://api.mention.net/api/accounts/$this->accountid/alerts", false, $context))->alerts;

        foreach ($alerts as $alert)
        {
            if (substr($alert->name, 0, 1) != '.')
            {
                $lastmentions = rglob("$this->basedir/staging/mention/$alert->name/*");
                rsort($lastmentions);
                
                if (count($lastmentions))
                {
                    $lastmentionid = array_pop(explode('/', str_replace('.json', '', array_shift($lastmentions))));
                }
                else
                {
                    $lastmentionid = 0;
                }

                $mentions = json_decode(file_get_contents("https://api.mention.net/api/accounts/$this->accountid/alerts/$alert->id/mentions?since_id=$lastmentionid&limit=100", false, $context))->mentions;
    
                var_dump($alert->name);
                var_dump($lastmentionid);
                var_dump(count($mentions));

                foreach ($mentions as $mention)
                {
                    foreach ($mention->children->children as $child)
                    {
                        $mentions[] = $child;
                    }

                    unset($mention->children);
                }

                foreach ($mentions as $mention)
                {
                    if ($mention->read && $mention->folder = 'inbox')
                    {
                        $time = strtotime($mention->created_at);
                        $mentiondate = date('Ym', $time);
                        mkdir("$this->basedir/staging/mention/$alert->name/$mentiondate", 0755, TRUE);
                        
                        if (!file_exists("$this->basedir/staging/mention/$alert->name/$mentiondate/$mention->id.json"))
                        {
                            file_put_contents("$this->basedir/staging/mention/$alert->name/$mentiondate/$mention->id.json", json_encode($mention));
                        }
                    }
                }
                /*
                foreach (glob("$this->basedir/staging/mention/$alert->name/*") as $dir)
                {
                    
                    $time = new Datetime('now');
                    $currentdir = $time->format('Ym');
                    $dirname = array_pop(explode('/', $dir));

                    if ($dirname < $currentdir)
                    {
                        $mentionviews = 0;

                        var_dump(count(glob("$dir/*")));

                        foreach (glob("$dir/*") as $mentionfile)
                        {
                            $mentioncontent = json_decode(file_get_contents($mentionfile));
                            if ($mentioncontent->cumulative_reach)
                            {
                                $reach = $mentioncontent->cumulative_reach;
                            }
                            else
                            {
                                if ($mentioncontent->domain_reach)
                                {
                                    $reach = $mentioncontent->domain_reach;
                                }
                            }

                            if ($reach)
                            {
                                $mentionviews = $mentionviews + $reach;
                            }
                        }

                        $rollup = json_encode(array(
                            "Reports" => count(glob("$dir/*")),
                            "Impressions" => $mentionviews
                        ));

                        mkdir("$this->basedir/analytics/$alert->name/digital", 0755, TRUE);
                        file_put_contents("$this->basedir/analytics/$alert->name/digital/$dirname", $rollup);
                        super_rmdir($dir);
                    }
                }
                */
            }
        }
    }

    function classify()
    {
        $date = new DateTime('now');
        $currentmonth = $date->format('Ym');

        foreach (glob("$this->basedir/staging/mention/*") as $customerdir)
        {
            $customer = array_pop(explode('/', $customerdir));

            foreach (glob("$customerdir/*") as $monthdir)
            {
                $month = array_pop(explode('/', $monthdir));

                if ($month < $currentmonth)
                {
                    foreach (glob("$monthdir/*") as $report)
                    {
                        $reportid = array_pop(explode('/', $report));

                        $report = json_decode(file_get_contents($report));

                        $category = $report->source_type;

                        /* start rewrite conditions */

                        if ($category == 'facebook' || $category == 'twitter' || $category == 'instagram')
                        {
                            $category = 'social';
                        }
                        
                        if ($category == 'images')
                        {
                            if (strpos($report->original_url, 'instagram.com'))
                            {
                                $category = 'social';
                            }
                            else
                            {
                                $category = 'web';
                            }
                        }

                        if ($category == 'blogs')
                        {
                            $category = 'web';
                        }

                        if ($category == 'videos')
                        {
                            if (strpos($report->original_url, 'youtube.com'))
                            {
                                $category = 'social';
                            }
                            else
                            {
                                $category = 'web';
                            }
                        }

                        if ($category == 'news' || $category == 'forums')
                        {
                            $category = 'web';
                        }

                        /* end rewrite conditions */


                        $reporttime = strtotime($report->created_at);

                        $tinyreport = new stdClass();

                        $tinyreport->time = $reporttime;
                        $tinyreport->impressions = $report->cumulative_reach;
                        $tinyreport->value = NULL;

                        mkdir("$this->basedir/analytics/$category/$reporttime", 0755, TRUE);

                        file_put_contents("$this->basedir/analytics/$customer/$category/$reporttime/$reportid", json_encode($tinyreport));
                    }
                }
            }
        }
    }
}

class BurrellesLuce
{
    public $basedir;
    private $username;
    private $password;
    private $session;

    function __construct()
    {
        $this->username = "";
        $password = "";
        $this->password = md5($this->username . $password);
        global $basedir;
        $this->basedir = $basedir;

        $this->connect();
    }

    function connect()
    {
        $this->session = curl_init();

        $fields = array(
            'hdnLogin' => '',
            'txtLogin' => $this->username,
            'txtPassword' => $this->password,
            'cmdLogin' => 'GO'
        );

        $fields = array_merge($fields, $this->validateevent('https://blportal.burrellesluce.com/Index.aspx'));

        foreach ($fields as $key=>$value)
        {
            $fields_string .= $key.'='.$value.'&';
        }
        rtrim($fields_string, '&');

        curl_setopt($this->session, CURLOPT_URL, 'https://blportal.burrellesluce.com/Index.aspx');
        curl_setopt($this->session, CURLOPT_SSL_VERIFYPEER,0);       // Allow self-signed certs
        curl_setopt($this->session, CURLOPT_SSL_VERIFYHOST,0);       // Allow certs that do not match the hostname
        curl_setopt($this->session, CURLOPT_POST, true);
        curl_setopt($this->session, CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($this->session, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->session, CURLOPT_COOKIESESSION, true);
        curl_setopt($this->session, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($this->session, CURLOPT_COOKIEJAR, '../../../data/evanslarson.com/app/cookiejar/burrellesluce/burrellesluce.txt');
        $answer = curl_exec($this->session);
        if (curl_error($this->session)) {
            echo curl_error($this->session);
        }
    }

    function validateevent($url)
    {
        curl_setopt($this->session, CURLOPT_URL, $url);
        curl_setopt($this->session, CURLOPT_SSL_VERIFYPEER,0);       // Allow self-signed certs
        curl_setopt($this->session, CURLOPT_SSL_VERIFYHOST,0);       // Allow certs that do not match the hostname
        curl_setopt($this->session, CURLOPT_POST, false);
        curl_setopt($this->session, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->session, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($this->session, CURLOPT_POSTFIELDS, "");
        $answer = curl_exec($this->session);
        if (curl_error($this->session)) {
            echo curl_error($this->session);
        }

        var_dump($answer);

        $dom = new DOMDocument();
        $dom->loadHTML($answer);
        $xpath = new DomXPath($dom);

        /* Magic 3 Values Below */

        $viewstate = $xpath->query("//*[@id='__VIEWSTATE']")->item(0)->getAttribute('value');
        $viewstategenerator = $xpath->query("//*[@id='__VIEWSTATEGENERATOR']")->item(0)->getAttribute('value');
        $eventvalidation = $xpath->query("//*[@id='__EVENTVALIDATION']")->item(0)->getAttribute('value');

        return array(
            "__VIEWSTATE" => str_replace('+', '%2B', $viewstate),
            "__VIEWSTATEGENERATOR" => str_replace('+', '%2B', $viewstategenerator),
            "__EVENTVALIDATION" => str_replace('+', '%2B', $eventvalidation)
        );
    }

    function collect()
    {
        set_time_limit(0);

        $reportid = '161592';

        $rphome = "https://blportal.burrellesluce.com/Reporting/RPHome.aspx";

        if (count(glob("$this->basedir/staging/burrelles/*")))
        {
            $date = new DateTime('January 1');
            $fromdate = $date->format('m/d/Y');
        }
        else
        {
            $date = new DateTime('-1 month');
            $fromdate = $date->format('m/d/Y');
        }

        $date = new DateTime('now');
        $todate = $date->format('m/d/Y');

        $this->validateevent($rphome);

        $fields = array(
            '__EVENTTARGET' => '',
            '__EVENTARGUMENT' => '',
            '__LASTFOCUS' => '',
            'h_ctl00_MasterBodyContent_RPReport_calFromDate' => '',
            'h_ctl00_MasterBodyContent_RPReport_calToDate' => '',
            'h_ctl00_MasterBodyContent_RPReport_calNewSinceDate' => '',
            'ctl00$MasterLeftNav$hdnLeftnav' => '',
            'ctl00$MasterLeftNav$hdnInnerLeftnav' => '#tabManage',
            'ctl00$MasterLeftNav$ddlReportList' => '-2',
            'ctl00$MasterLeftNav$ddlProjectList' => $reportid,
            'ctl00$MasterLeftNav$hdReportID' => '',
            'ctl00$MasterBodyContent$RPReport$hdnSelProjs' => '182347|182347,202725|202725,182371|182371,182355|182355,182355|183514,182359|182359,182356|182356,182357|182357,182349|182349,182348|182348,182353|182353,182351|182351,182360|182360,182370|182370,193612|193612,182358|182358,182350|182350,182363|182363,182352|182352,182354|182354,182364|182364,182368|182368,184008|184008,182361|182361,182366|182366,182327|182327,182367|182367,183379|183379,182326|182326',
            'ctl00$MasterBodyContent$RPReport$hdnUnselProjs' => '',
            'ctl00$MasterBodyContent$RPReport$hdnSelColName' => '5,7,10,21,22',
            'ctl00$MasterBodyContent$RPReport$hdnUnselColName' => '0,2,13,23,3,4,6,8,9,11,12,30,31,32',
            'ctl00$MasterBodyContent$RPReport$hdnSelCompare' => '',
            'ctl00$MasterBodyContent$RPReport$hdnUnselCompare' => '',
            'ctl00$MasterBodyContent$RPReport$selectPanel' => '',
            'ctl00$MasterBodyContent$RPReport$hdnDefaultView' => 'Collapse',
            'ctl00$MasterBodyContent$RPReport$txtReportName' => '1 - All Malls Coverage',
            'ctl00$MasterBodyContent$RPReport$ddlReportType' => '0',
            'ctl00$MasterBodyContent$RPReport$txtFromDate' => $fromdate,
            'ctl00$MasterBodyContent$RPReport$txtToDate' => $todate,
            'ctl00$MasterBodyContent$RPReport$ddlDateType' => 'Publish',
            'ctl00$MasterBodyContent$RPReport$txtNewSinceDate' => '',
            'ctl00$MasterBodyContent$RPReport$ddlNewSinceTime' => '',
            'ctl00$MasterBodyContent$RPReport$txtTitle' => '',
            'ctl00$MasterBodyContent$RPReport$txtDescription' => '',
            'ctl00$MasterBodyContent$RPReport$rblGroupBy' => 'Date',
            'ctl00$MasterBodyContent$RPReport$rblOrder' => '',
            'ctl00$MasterBodyContent$RPReport$rbtn' => '2',
            'ctl00$MasterBodyContent$RPReport$rblProminenceMode' => 'AND',
            'ctl00$MasterBodyContent$RPReport$txtProminence' => '',
            'ctl00$MasterBodyContent$RPReport$ddlImpMult' => '1.0',
            'ctl00$MasterBodyContent$RPReport$ddlPubMult' => '1.0',
            'ctl00$MasterBodyContent$RPReport$txtTonePositive' => '',
            'ctl00$MasterBodyContent$RPReport$txtToneNegative' => '',
            'ctl00$MasterBodyContent$RPReport$hfProminence' => '',
            'ctl00$MasterBodyContent$RPReport$btnSave' => 'Save',
        );

        $fields = array_merge($fields, $this->validateevent("$rphome?r=$reportid"));

        var_dump($fields);

        foreach ($fields as $key=>$value)
        {
            $fields_string .= $key.'='.$value.'&';
        }
        rtrim($fields_string, '&');

        curl_setopt($this->session, CURLOPT_URL, "$rphome?r=$reportid");
        curl_setopt($this->session, CURLOPT_POST, true);
        curl_setopt($this->session, CURLOPT_POSTFIELDS, $fields_string);
        $answer = curl_exec($this->session);
        if (curl_error($this->session)) {
            echo curl_error($this->session);
        }

        var_dump($answer);

        $fields_string = null;

        $fields = array(
            '__EVENTTARGET' => 'lnkExcel',
            '__EVENTARGUMENT' => '',
            '__SCROLLPOSITIONX' => '',
            '__SCROLLPOSITIONY' => '',
        );

        $fields = array_merge($fields, $this->validateevent("https://blportal.burrellesluce.com/Reporting/RPExport.aspx?r=$reportid"));

        var_dump($fields);

        foreach ($fields as $key=>$value)
        {
            $fields_string .= $key.'='.$value.'&';
        }
        rtrim($fields_string, '&');

        curl_setopt($this->session, CURLOPT_URL, "https://blportal.burrellesluce.com/Reporting/RPExport.aspx?r=$reportid");
        curl_setopt($this->session, CURLOPT_POST, true);
        curl_setopt($this->session, CURLOPT_POSTFIELDS, $fields_string);
        $answer = curl_exec($this->session);
        if (curl_error($this->session)) {
            echo curl_error($this->session);
        }

        var_dump($answer);

        
        $dom = new DOMDocument();
        $dom->loadHTML($answer);
        $xpath = new DomXPath($dom);

        $reports = array();
        $folders = $xpath->query("//div[@style='font-weight:bold']");
        $tables = $xpath->query("//table");

        var_dump("here are folders");
        var_dump($tables->length);

        if ($tables->length == $folders->length)
        {
            for ($i = 0; $i <= $folders->length; $i++)
            {
                if ($folders[$i]->nodeValue)
                {
                    $folder = str_replace('Folder: ', '', $folders[$i]->nodeValue);
                    var_dump("here is $folder");

                    mkdir("$this->basedir/staging/burrelles/$folder", 0755, TRUE);
                    
                    $table = $tables[$i]->ownerDocument->saveXML($tables[$i]);

                    $keys = array();

                    //var_dump($table);

                    $dom2 = new DOMDocument();
                    $dom2->loadHTML($table);
                    $xpath = new DomXPath($dom2);

                    $rows = $xpath->query("//tr");

                    for ($j = 0; $j <= $rows->length; $j++)
                    {
                        if ($rows[$j])
                        {
                            $row = $rows[$j]->ownerDocument->saveXML($rows[$j]);

                            $dom3 = new DOMDocument();
                            $dom3->loadHTML($row);
                            $xpath = new DomXPath($dom3);
    
                            $ths = $xpath->query("//th");
                            $tds = $xpath->query("//td");
    
                            for ($k = 0; $k <= $ths->length; $k++)
                            {
                                if ($ths[$k]->nodeValue)
                                {
                                    $key = $ths[$k]->nodeValue;
                                    $keys[] = $key;
                                    var_dump($key);
                                }
                            }
    
                            $report = array();
    
                            for ($l = 0; $l <= count($keys); $l++)
                            {
                                if ($keys[$l] && $tds[$l]->nodeValue)
                                {
                                    $report[$keys[$l]] = $tds[$l]->nodeValue;
                                }
                            }
    
                            if (count($report))
                            {
                                $reportid = array_pop(explode('/', $report['URL']));

                                $date = DateTime::createFromFormat('n/d/Y', $report['Pub. Date']);
                                $serialmonth = $date->format('Ym');

                                mkdir("$this->basedir/staging/burrelles/$folder/$serialmonth", 0755, TRUE);
                                
                                if (file_exists("$this->basedir/staging/burrelles/$folder/$serialmonth/$reportid.json"))
                                {
                                    echo "$folder $reportid already exists";
                                }
                                else
                                {
                                    file_put_contents("$this->basedir/staging/burrelles/$folder/$serialmonth/$reportid.json", json_encode($report));
                                }
                                var_dump($report);
                            }
                        }
                    }
                }
            }
        }
    }
}

class Hootsuite
{
    public $basedir;
    private $username;
    private $password;
    private $session;

    function __construct()
    {
        $this->username = "";
        $this->password = "";
        global $basedir;
        $this->basedir = $basedir;

        $this->connect();
    }

    function connect()
    {
        $this->session = curl_init();
        curl_setopt($this->session, CURLOPT_URL, 'https://hootsuite.com/login?method=openId');
        curl_setopt($this->session, CURLOPT_SSL_VERIFYPEER,0);       // Allow self-signed certs
        curl_setopt($this->session, CURLOPT_SSL_VERIFYHOST,0);       // Allow certs that do not match the hostname
        curl_setopt($this->session, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->session, CURLOPT_COOKIESESSION, true);
        curl_setopt($this->session, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($this->session, CURLOPT_COOKIEJAR, '../../../data/evanslarson.com/app/cookiejar/hootsuite/hootsuite.txt');
        $answer = curl_exec($this->session);
        if (curl_error($this->session)) {
            echo curl_error($this->session);
        }


        $loginHTML = $answer;
        $dom = new DOMDocument();
        $dom->loadHTML($loginHTML);
        $xpath = new DomXPath($dom);

        $matches = array();
        preg_match('/(?<=csrfToken)(.+?)(?=\;)/', $loginHTML, $matches);
        $csrfToken = preg_replace('/[^0-9a-zA-Z:,]+/', '', array_pop($matches));

        $loginCsrfToken = $xpath->query("//*[@name='loginCsrfToken']")->item(0)->getAttribute('value');
        var_dump($csrfToken);
        

        $fields = array(
            'email' => $this->username,
            'password' => $this->password,
            'googleAuthenticator' => '',
            'method' => 'email',
            'loginCsrfToken' => $loginCsrfToken,
            'csrfToken' => $csrfToken
        );

        foreach ($fields as $key=>$value)
        {
            $fields_string .= $key.'='.$value.'&';
        }
        rtrim($fields_string, '&');


        curl_setopt($this->session, CURLOPT_URL, 'https://hootsuite.com/login');
        curl_setopt($this->session, CURLOPT_POST, true);
        curl_setopt($this->session, CURLOPT_POSTFIELDS, $fields_string);
        $answer = curl_exec($this->session);
        if (curl_error($this->session)) {
            echo curl_error($this->session);
        }
        var_dump($answer);
    }

    function collect()
    {
        set_time_limit(0);

        curl_setopt($this->session, CURLOPT_URL, 'https://hootsuite.com/dashboard');
        curl_setopt($this->session, CURLOPT_POST, false);
        curl_setopt($this->session, CURLOPT_POSTFIELDS, "");
        $answer = curl_exec($this->session);
        if (curl_error($this->session)) {
            echo curl_error($this->session);
        }

        var_dump($answer);
    }
}

function collect()
{
    $providername = sanitize($_GET['provider']);

    $provider = new $providername;

    $provider->collect();
}

function classify()
{
    $providername = sanitize($_GET['provider']);

    $provider = new $providername;

    $provider->classify();
}

$method = sanitize($_GET['method']);

if ($method)
{
    $method();
}



?>