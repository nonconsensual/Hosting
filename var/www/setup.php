<?php
include('common.php');
if(!extension_loaded('pdo_mysql')){
	die("Error: You need to install and enable the PDO php module\n");
}
try{
	$db=new PDO('mysql:host=' . DBHOST . ';dbname=' . DBNAME . ';charset=utf8mb4', DBUSER, DBPASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_WARNING, PDO::ATTR_PERSISTENT=>PERSISTENT]);
}catch(PDOException $e){
	try{
		//Attempt to create database
		$db=new PDO('mysql:host=' . DBHOST . ';charset=utf8mb4', DBUSER, DBPASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_WARNING, PDO::ATTR_PERSISTENT=>PERSISTENT]);
		if(false!==$db->exec('CREATE DATABASE ' . DBNAME)){
			$db=new PDO('mysql:host=' . DBHOST . ';dbname=' . DBNAME . ';charset=utf8mb4', DBUSER, DBPASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_WARNING, PDO::ATTR_PERSISTENT=>PERSISTENT]);
		}else{
			die("Error: No database connection!\n");
		}
	}catch(PDOException $e){
		die("Error: No database connection!\n");
	}
}
$version;
if(!@$version=$db->query("SELECT value FROM settings WHERE setting='version';")){
	//create tables
	$db->exec('CREATE TABLE captcha (id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, time int(11) NOT NULL, code char(5) COLLATE latin1_bin NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_bin;');
	$db->exec('CREATE TABLE users (id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, system_account varchar(32) COLLATE latin1_bin NOT NULL UNIQUE, username varchar(50) COLLATE latin1_bin NOT NULL UNIQUE, password varchar(255) COLLATE latin1_bin NOT NULL, dateadded int(10) unsigned NOT NULL, public tinyint(1) unsigned NOT NULL, php tinyint(1) unsigned NOT NULL, autoindex tinyint(1) unsigned NOT NULL, todelete tinyint(1) UNSIGNED NOT NULL, mysql_user varchar(32) NOT NULL, KEY dateadded (dateadded), KEY public (public), KEY todelete (todelete)) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_bin;');
	$db->exec('CREATE TABLE new_account (user_id int(11) NOT NULL PRIMARY KEY, password varchar(255) COLLATE latin1_bin NOT NULL, approved tinyint(1) UNSIGNED NOT NULL, CONSTRAINT new_account_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_bin;');
	$db->exec('CREATE TABLE pass_change (user_id int(11) NOT NULL PRIMARY KEY, password varchar(255) COLLATE latin1_bin NOT NULL, CONSTRAINT pass_change_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_bin;');
	// code error $db->exec('CREATE TABLE mysql_databases (user_id int(11) NOT NULL, mysql_database varchar(64) COLLATE latin1_bin NOT NULL, KEY user_id, CONSTRAINT mysql_database_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_bin;');
	$db->exec('CREATE TABLE mysql_databases (user_id int(11) NOT NULL, mysql_database varchar(64) COLLATE latin1_bin NOT NULL, KEY user_id (user_id), CONSTRAINT mysql_database_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_bin;');
	$db->exec("CREATE TABLE onions (user_id int(11) NOT NULL, onion varchar(56) COLLATE latin1_bin NOT NULL PRIMARY KEY, private_key varchar(1000) COLLATE latin1_bin NOT NULL, version tinyint(1) NOT NULL, enabled tinyint(1) NOT NULL DEFAULT '1', num_intros tinyint(3) NOT NULL DEFAULT '3', enable_smtp tinyint(1) NOT NULL DEFAULT '1', max_streams tinyint(3) unsigned NOT NULL DEFAULT '20', KEY user_id (user_id), KEY enabled (enabled), CONSTRAINT onions_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_bin;");
	$db->exec("CREATE TABLE service_instances (id char(1) NOT NULL PRIMARY KEY, reload tinyint(1) UNSIGNED NOT NULL DEFAULT '0', KEY reload (reload)) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_bin;");
	$stmt=$db->prepare('INSERT INTO service_instances (id) VALUES (?);');
	foreach(SERVICE_INSTANCES as $key){
		$stmt->execute([$key]);
	}
	$db->exec('CREATE TABLE settings (setting varchar(50) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL PRIMARY KEY, value text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_bin;');
	$stmt=$db->prepare("INSERT INTO settings (setting, value) VALUES ('version', ?);");
	$stmt->execute([DBVERSION]);
	foreach(PHP_VERSIONS as $version){
		if(!file_exists("/etc/php/$version/fpm/conf.d/")){
			mkdir("/etc/php/$version/fpm/conf.d/", 0755, true);
		}
		file_put_contents("/etc/php/$version/fpm/conf.d/99-hosting.conf", PHP_CONFIG);
		if(!file_exists("/etc/php/$version/cli/conf.d/")){
			mkdir("/etc/php/$version/cli/conf.d/", 0755, true);
		}
		file_put_contents("/etc/php/$version/cli/conf.d/99-hosting.conf", PHP_CONFIG);
		$fpm_config = "[global]
pid = /run/php/php$version-fpm.pid
error_log = /var/log/php$version-fpm.log
process_control_timeout = 10
include=/etc/php/$version/fpm/pool.d/*.conf
";
		file_put_contents("/etc/php/$version/fpm/php-fpm.conf", $fpm_config);
		$pool_config = "[www]
user = www-data
group = www-data
listen = /run/php/php$version-fpm.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 25
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
php_admin_value[mysqli.allow_persistent] = On
";
		if(!file_exists("/etc/php/$version/fpm/pool.d/")){
			mkdir("/etc/php/$version/fpm/pool.d/", 0755, true);
		}
		file_put_contents("/etc/php/$version/fpm/pool.d/www.conf", $pool_config);
		foreach(SERVICE_INSTANCES as $instance){
			$fpm_config = "[global]
pid = /run/php/php$version-fpm-$instance.pid
error_log = /var/log/php$version-fpm-$instance.log
process_control_timeout = 10
include=/etc/php/$version/fpm/pool.d/$instance/*.conf
";
			file_put_contents("/etc/php/$version/fpm/php-fpm-$instance.conf", $fpm_config);
			$pool_config = "[www]
user = www-data
group = www-data
listen = /run/php/$version-$instance
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 8
";
			if(!file_exists("/etc/php/$version/fpm/pool.d/$instance/")){
				mkdir("/etc/php/$version/fpm/pool.d/$instance/", 0755, true);
			}
			file_put_contents("/etc/php/$version/fpm/pool.d/$instance/www.conf", $pool_config);
		}
	}
	echo "Database and files have successfully been set up\n";
}else{
	$version=$version->fetch(PDO::FETCH_NUM)[0];
	if($version<2){
		$db->exec('ALTER TABLE users ADD todelete tinyint(1) UNSIGNED NOT NULL, ADD INDEX(todelete);');
		$db->exec('ALTER TABLE new_account ADD approved tinyint(1) UNSIGNED NOT NULL;');
		$db->exec('DROP TABLE del_account;');
	}
	if($version<4){
		$db->exec('ALTER TABLE new_account DROP FOREIGN KEY new_account_ibfk_1;');
		$db->exec('ALTER TABLE pass_change DROP FOREIGN KEY pass_change_ibfk_1;');
		$db->exec('ALTER TABLE users DROP PRIMARY KEY;');
		$db->exec('ALTER TABLE users ADD id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT FIRST;');
		$db->exec('ALTER TABLE users ADD UNIQUE (onion);');
		$db->exec('RENAME TABLE new_account TO copy_new_account;');
		$db->exec('CREATE TABLE new_account (user_id int(11) NOT NULL PRIMARY KEY, password varchar(255) COLLATE latin1_bin NOT NULL, approved tinyint(1) UNSIGNED NOT NULL, CONSTRAINT new_account_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_bin;');
		$db->exec('INSERT INTO new_account SELECT users.id, copy_new_account.password, copy_new_account.approved FROM copy_new_account INNER JOIN users ON (users.onion=copy_new_account.onion);');
		$db->exec('DROP TABLE copy_new_account;');
		$db->exec('RENAME TABLE pass_change TO copy_pass_change;');
		$db->exec('CREATE TABLE pass_change (user_id int(11) NOT NULL PRIMARY KEY, password varchar(255) COLLATE latin1_bin NOT NULL, CONSTRAINT pass_change_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_bin;');
		$db->exec('INSERT INTO pass_change SELECT users.id, copy_pass_change.password FROM copy_pass_change INNER JOIN users ON (users.onion=copy_pass_change.onion);');
		$db->exec('DROP TABLE copy_pass_change;');
	}
	if($version<5){
		$db->exec('ALTER TABLE users ADD mysql_user varchar(32) NOT NULL;');
		$db->exec("UPDATE users SET mysql_user=CONCAT(onion, '.onion');");
		$db->exec('CREATE TABLE mysql_databases (user_id int(11) NOT NULL KEY, mysql_database varchar(64) COLLATE latin1_bin NOT NULL, CONSTRAINT mysql_database_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_bin;');
		$db->exec("INSERT INTO mysql_databases (user_id, mysql_database) SELECT id, onion FROM users;");
	}
	if($version<6){
		$db->exec('ALTER TABLE mysql_databases DROP PRIMARY KEY, ADD INDEX user_id (user_id);');
		$db->exec("CREATE TABLE onions (user_id int(11) NOT NULL, onion varchar(56) COLLATE latin1_bin NOT NULL PRIMARY KEY, private_key varchar(1000) COLLATE latin1_bin NOT NULL, version tinyint(1) NOT NULL, enabled tinyint(1) NOT NULL DEFAULT '1', num_intros tinyint(3) NOT NULL DEFAULT '3', enable_smtp tinyint(1) NOT NULL DEFAULT '1', KEY user_id (user_id), KEY enabled (enabled), CONSTRAINT onions_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_bin;");
		$db->exec("INSERT INTO onions (user_id, onion, private_key, version) SELECT id, onion, private_key, 2 FROM users;");
		$db->exec('ALTER TABLE users DROP private_key;');
		$db->exec('ALTER TABLE users CHANGE onion system_account varchar(32) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL;');
		$db->exec("UPDATE users SET system_account = CONCAT(system_account, '.onion');");
		$stmt=$db->query("SELECT system_account FROM users;");
		while($id=$stmt->fetch(PDO::FETCH_NUM)){
			$system_account=$id[0];
			$onion=substr($id[0], 0, 16);
			$replace=preg_replace("~listen\sunix:/var/run/nginx(/[a-z2-7]{16}|\.sock)(\sbacklog=2048)?;~", "listen unix:/var/run/nginx/$system_account backlog=2048;", file_get_contents("/etc/nginx/sites-enabled/$system_account"));
			file_put_contents("/etc/nginx/sites-enabled/$system_account", $replace);
		}
		exec('service nginx reload');
	}
	if($version<7){
		$db->exec("ALTER TABLE onions ADD max_streams tinyint(3) unsigned NOT NULL DEFAULT '20';");
		$db->exec("CREATE TABLE service_instances (id char(1) NOT NULL PRIMARY KEY, reload tinyint(1) UNSIGNED NOT NULL DEFAULT '0', KEY reload (reload)) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_bin;");
		$stmt=$db->prepare('INSERT INTO service_instances (id, reload) VALUES (?, 1)');
		foreach(SERVICE_INSTANCES as $key){
			$stmt->execute([$key]);
		}
	}
	if($version<8){
		foreach(PHP_VERSIONS as $version){
			if(!file_exists("/etc/php/$version/fpm/conf.d/")){
				mkdir("/etc/php/$version/fpm/conf.d/", 0755, true);
			}
			file_put_contents("/etc/php/$version/fpm/conf.d/99-hosting.conf", PHP_CONFIG);
			if(!file_exists("/etc/php/$version/cli/conf.d/")){
				mkdir("/etc/php/$version/cli/conf.d/", 0755, true);
			}
			file_put_contents("/etc/php/$version/cli/conf.d/99-hosting.conf", PHP_CONFIG);
			$fpm_config = "[global]
pid = /run/php/php$version-fpm.pid
error_log = /var/log/php$version-fpm.log
process_control_timeout = 10
include=/etc/php/$version/fpm/pool.d/*.conf
";
			file_put_contents("/etc/php/$version/fpm/php-fpm.conf", $fpm_config);
			$pool_config = "[www]
user = www-data
group = www-data
listen = /run/php/php$version-fpm.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 25
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
php_admin_value[mysqli.allow_persistent] = On
";
			if(!file_exists("/etc/php/$version/fpm/pool.d/")){
				mkdir("/etc/php/$version/fpm/pool.d/", 0755, true);
			}
			file_put_contents("/etc/php/$version/fpm/pool.d/www.conf", $pool_config);
			foreach(SERVICE_INSTANCES as $instance){
				$fpm_config = "[global]
pid = /run/php/php$version-fpm-$instance.pid
error_log = /var/log/php$version-fpm-$instance.log
process_control_timeout = 10
include=/etc/php/$version/fpm/pool.d/$instance/*.conf
";
				file_put_contents("/etc/php/$version/fpm/php-fpm-$instance.conf", $fpm_config);
				$pool_config = "[www]
user = www-data
group = www-data
listen = /run/php/$version-$instance
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 8
";
				if(!file_exists("/etc/php/$version/fpm/pool.d/$instance/")){
					mkdir("/etc/php/$version/fpm/pool.d/$instance/", 0755, true);
				}
				file_put_contents("/etc/php/$version/fpm/pool.d/$instance/www.conf", $pool_config);
			}
		}
	}
	$stmt=$db->prepare("UPDATE settings SET value=? WHERE setting='version';");
	$stmt->execute([DBVERSION]);
	if(DBVERSION!=$version){
		echo "Database and files have successfully been updated to the latest version\n";
	}else{
		echo "Database and files already up-to-date\n";
	}
}
