#!/usr/bin/php
<?php

// PHP Weathermap 0.98b
// Copyright Howard Jones, 2005-2020 howie@thingy.com
// http://www.network-weathermap.com/
// Released under the MIT License
require_once __DIR__.'/lib/Console/Getopt.php';

require_once "lib/Weathermap.class.php";

include_once 'config.inc.php';

if (!wm_module_checks()) { die ("Quitting: Module checks failed.\n"); }

//    $weathermap_debugging=TRUE; 

$output="html";
$configfile="weathermap.conf";
$htmlfile='';
$imagefile='';
$dumpafter=0;
$dumpstats=0;
$randomdata=0;
$dumpconfig='';
$defines=array();
$daemon=0;
$daemon_args="";

// **************************************************************************************
// THIS IS THE ONE LINE IN HERE YOU MIGHT HAVE TO CHANGE!
$rrdtool="/usr/bin/rrdtool";
// (on Windows, use / instead of \ in pathnames - c:/rrdtool/bin/rrdtool.exe for example)
// **************************************************************************************

// initialize object
$cg=new Console_Getopt();
$short_opts='';
$long_opts=array
	(
		"version",
		"help",
		"image-uri=",
		"base-href=",
		"config=",
		"output=",
		"debug",
		"uberdebug",
		"stats",
		"define=",
		"no-data",
		"randomdata",
		"htmloutput=",
		"dumpafter",
		"bulge",
		"sizedebug",
		"dumpconfig=",
        "daemon=",
		"chdir="
	);

$args=$cg->readPHPArgv();

$ret=$cg->getopt($args, $short_opts, $long_opts);

$pear = new PEAR;
if ($pear->isError($ret)) { die ("Error in command line: " . $ret->getMessage() . "\n (try --help)\n"); }

$gopts=$ret[0];

$options_output = array();

if (sizeof($gopts) > 0)
{
	foreach ($gopts as $o)
	{
		switch ($o[0])
		{
		case '--config':
			$configfile=$o[1];			
			break;

		case '--htmloutput':
			$htmlfile=$o[1];
			break;

		case '--dumpafter':
			$dumpafter=1;
			break;

		case '--image-uri':
			// $map->imageuri=$o[1];
			$options_output['imageuri'] = $o[1];
			break;

		case '--base-href':
			// $map->basehref=$o[1];
			$options_output['basehref'] = $o[1];
			break;
		case '--dumpconfig':
			//$map->dumpconfig=$o[1];
			// $options_output['dumpconfig'] = $o[1];
			$dumpconfig=$o[1];
			break;

		case '--randomdata':
			$randomdata=1;
			break;

		case '--stats':
			$dumpstats=1;
			break;
		case '--no-warn':
			// allow disabling of warnings from the command-line, too (mainly for the rrdtool warning)
			$suppress_warnings = explode(",", $o[1]);
			foreach ($suppress_warnings as $s) {
				$weathermap_error_suppress[] = strtoupper($s);
			}
			break;

		case '--uberdebug':
			// allow ALL trace messages (normally we block some of the chatty ones)
			$weathermap_debug_suppress=array();
			// FALL THROUGH
		case '--debug':
			$options_output['debugging'] = TRUE;
			$weathermap_debugging=TRUE;
			
			// enable assertion handling
			assert_options(ASSERT_ACTIVE, 1);
			assert_options(ASSERT_WARNING, 0);
			assert_options(ASSERT_QUIET_EVAL, 1);

			// Set up the callback
			assert_options(ASSERT_CALLBACK, 'my_assert_handler');
			
			break;

		case '--sizedebug':
		case '--no-data':
			//$map->sizedebug=TRUE;
			$options_output['sizedebug'] = TRUE;
			break;

		case '--bulge':
			//$map->widthmod=TRUE;
			$options_output['widthmod'] = TRUE;
			break;

		case '--output':
			$imagefile=$o[1];
			break;

        case '--daemon':
            $daemon=1;
            $daemon_args=$o[1];
            #$rrdtool = $rrdtool . " --daemon ".$o[1];
            break;

        case '--chdir':
		    $chdir = $o[1];
            break;
			
        case '--define':
            preg_match("/^([^=]+)=(.*)\s*$/",$o[1],$matches);
			if(isset($matches[2]))
			{
	            $varname = $matches[1];
	            $value = $matches[2];
				wm_debug(">> $varname = '$value'\n");
	            // save this for later, so that when the map object exists, it can be defined
	            $defines[$varname]=$value;
			}
			else
			{
				print "WARNING: --define format is:  --define name=value\n";
			}            
            break;

		case '--version': 
			print 'PHP Network Weathermap v' . $WEATHERMAP_VERSION."\n";
			exit();
			break;

		case '--help':
                        print 'PHP Network Weathermap v' . $WEATHERMAP_VERSION."\n";
                        print "Copyright Howard Jones, 2005-2020 howie@thingy.com\nReleased under the MIT License\nhttp://www.network-weathermap.com/\n\n";

			            print "Usage: php weathermap [options]\n\n";
                        
                        print " --config {filename}      -  filename to read from. Default weathermap.conf\n";
                        print " --output {filename}      -  filename to write image. Default weathermap.png\n";
                        print " --htmloutput {filename}  -  filename to write HTML. Default weathermap.html\n\n";
                        
			            print " --base-href {uri}        - URI for Weathermap, i.e /weathermap/\n";
                        print " --image-uri {uri}        -  URI to prefix <img> tags in HTML.\n";
                        print " --bulge                  -  Enable link-bulging mode. See manual.\n\n";
						
						print " --define name=value      -  Define internal variables\n";
						print "                             (equivalent to global SET in config file)\n\n";
                        
                        print " --no-data                -  skip the data-reading process (just a 'grey' map)\n";
                        print " --randomdata            -  as above, but use random data\n";
                        print " --debug                  -  produce (LOTS) of debugging information during run\n";
                        print " --dump-after             -  dump all internal PHP structures (HUGE)\n";
                        print " --dumpconfig {filename}  -  filename to write a new config to (for testing)\n\n";
                        
                        print " --help                   -  show this help\n";
                        print " --version                -  show version number\n\n";
                        print " --daemon {path}          -  path to rrdcached sock\n\n";
						print " --chdir {path}           -  path to change to before running rrdtool\n\n";
                        print "More info at http://www.network-weathermap.com/\n";
			exit();
			break;
		}
	}
}

// set this BEFORE we create the map object, so we get the debug output from Reset(), as well
if(isset($options_output['debugging']) && $options_output['debugging']) 
{
	$weathermap_debugging=TRUE;
	wm_debug("------------------------------------\n");
	wm_debug("Starting PHP-Weathermap run, with config: $configfile\n");
	wm_debug("------------------------------------\n");
}

$map=new Weathermap;
$map->rrdtool = $rrdtool;
$map->context="cli";
$map->daemon=$daemon;
$map->daemon_args=$daemon_args;

if(isset($chdir)) {
	$map->chdir=$chdir;
}

// now stuff in all the others, that we got from getopts
foreach ($options_output as $key=>$value)
{
	$map->$key = $value;
	// $map->add_hint($key,$value);
}

$weathermap_map = $configfile;

if ($map->ReadConfig($configfile))
{
	// allow command-lines to override the config file, but provide a default if neither are present
	if ($imagefile == '')
	{
		if ($map->imageoutputfile == '') { $imagefile="weathermap.png"; }
		else { $imagefile=$map->imageoutputfile; }
	}

	if ($htmlfile == '')
	{
		if ($map->htmloutputfile != '') { $htmlfile = $map->htmloutputfile; }
	}

    // feed in any command-line defaults, so that they appear as if SET lines in the config
    
    // XXX FIXME
    foreach ($defines as $hintname=>$hint)
    {
        $map->add_hint($hintname,$hint);
    }
	
	// now stuff in all the others, that we got from getopts
	foreach ($options_output as $key=>$value)
	{
		// $map->$key = $value;
		$map->add_hint($key,$value);
	}

	// Get rrd default path
    $rrdbase = $map->get_hint('rrd_default_path');

	// Added the rrd_default_path if cannot be found
    if($rrdbase == '') {
        $rrdbase = isset($chdir) ? $chdir : $rrd_default_path1;
        $map->add_hint('rrd_default_path', $rrdbase);

    }

    if ( (isset($options_output['sizedebug']) && ! $options_output['sizedebug']) || (!isset($options_output['sizedebug'])) )
	{
		if ($randomdata == 1) { $map->RandomData(); }
		else { $map->ReadData(); }
	}

	# exit();
	
	if ($imagefile != '')
	{
		$map->DrawMap($imagefile);
		$map->imagefile=$imagefile;
	}

	if ($htmlfile != '')
	{
		wm_debug("Writing HTML to $htmlfile\n");
		
		$fd=fopen($htmlfile, 'w');
		fwrite($fd,
			'<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd"><html xmlns="http://www.w3.org/1999/xhtml"><head>');
		fwrite($fd,"<base href=\"$map->basehref\">");
		if($map->htmlstylesheet != '') fwrite($fd,'<link rel="stylesheet" type="text/css" href="'.$map->htmlstylesheet.'" />');
		fwrite($fd,'<meta http-equiv="refresh" content="300" /><title>' . $map->ProcessString($map->title, $map) . '</title></head><body>');

		if ($map->htmlstyle == "overlib")
		{
			fwrite($fd,
				"<div id=\"overDiv\" style=\"position:absolute; visibility:hidden; z-index:1000;\"></div>\n");
			fwrite($fd,
				"<script type=\"text/javascript\" src=\"overlib.js\"><!-- overLIB (c) Erik Bosrup --></script> \n");
		}

		fwrite($fd, $map->MakeHTML());
		fwrite($fd,
			'<hr /><span id="byline">Network Map created with <a href="http://www.network-weathermap.com/?vs='
			. $WEATHERMAP_VERSION . '">PHP Network Weathermap v' . $WEATHERMAP_VERSION
			. '</a></span></body></html>');
		fclose ($fd);
	}

	if ($dumpconfig != '')
		$map->WriteConfig($dumpconfig);

	if ($dumpstats != '')
		$map->DumpStats();

	if ($dumpafter == 1)
		print_r ($map);
		
#	print_r ($map->node_template_tree);
#	print_r ($map->link_template_tree);
	
	# $map->cachefolder="editcache";	
	# $map->CacheUpdate(1);
	
		
}
else { die ("\n\nCould not read Weathermap config file. No output produced. Maybe try --help?\n"); }



function my_assert_handler($file, $line, $code)
{
    echo "Assertion Failed:
        File $file
        Line $line
        Code $code";
	debug_print_backtrace();
	exit();
}

// vim:ts=4:sw=4:
