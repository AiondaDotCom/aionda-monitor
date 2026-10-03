<?php
// HELPER UTILITIES

require_once(dirname(__FILE__).'/../config.inc.php');

// load cgi.cfg settings into $cfg
read_cgi_config_file();

////////////////////////////////////////////////////////////////////////////////////////////////
// FILE PROCESSING FUNCTIONS
////////////////////////////////////////////////////////////////////////////////////////////////

// reads variables from main config file
function read_main_config_file($thefile=""){
	global $cfg;
	
	static $already_called = false;
	static $contents=array();
	
	if (!$already_called) {
			
		// file name can be overridden from default
		if(isset($thefile) && $thefile!="")
			$fname=$thefile;
		else
			$fname=$cfg['main_config_file'];
			
		// open main config file for reading...
		if(($fh=@fopen($fname,'r'))!=FALSE){
			// read all lines in the config file
			while( ($s=fgets($fh)) !== false){
				// skip comments
                                if($s){
                                   if($s[0]=='#')
				      continue;
				}
				// skip blank lines
				// TODO - is this necessary?
				
				// split comments out from config
				$s2=explode(";",$s);
					
				// get var/val pairs
				$v=explode("=",$s2[0]);
				
				if(isset($v[0]) && isset($v[1])){

					// trim var/val pairs
					$v[0]=trim($v[0]);
					$v[1]=trim($v[1]);

					// allow for multiple values for some variables...
					$arr=false;
					if(!strcmp($v[0],"cfg_file"))
						$arr=true;
					else if(!strcmp($v[0],"cfg_dir"))
						$arr=true;
						
					if($arr==true)
						$contents[$v[0]][] = $v[1];
					else
						$contents[$v[0]] = $v[1];
					}
				}
			fclose($fh);
			}

		$already_called = true;
		}

	return $contents;
	}
	
	
// reads variables from cgi config file
function read_cgi_config_file($thefile=""){
	global $cfg;

	static $already_called = false;
	static $contents=array();

	if (!$already_called) {
		
		// file name can be overridden from default
		if(isset($thefile) && $thefile!="")
			$fname=$thefile;
		else
			$fname=$cfg['cgi_config_file'];
			
		// open cgi config file for reading...
		if(($fh=@fopen($fname,'r'))!=FALSE){
			// read all lines in the config file
			while( ($s=fgets($fh)) !== false){
				// skip comments
				if($s){
				   if($s[0]=='#')
					continue;
			        }
				
				// skip blank lines
				// TODO - is this necessary?
				
				// split comments out from config
				$s2=explode(";",$s);
					
				// get var/val pairs
				$v=explode("=",$s2[0]);
				
				if(isset($v[0]) && isset($v[1])){

					// trim var/val pairs
					$v[0]=ltrim(rtrim($v[0]));
					$v[1]=ltrim(rtrim($v[1]));

					// do not allow for multiple values
					$contents[$v[0]] = $v[1];
					$cfg[$v[0]] = $v[1];
					}
				}
			fclose($fh);
			}

		$already_called = true;
		}

	return $contents;
	}

?>