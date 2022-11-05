<?php

require_once(dirname(__FILE__).'/db_lib.php');
require_once(dirname(__FILE__).'/platform_lib.php');

class KeyMgmt
{
    public $ID;
    public $LabName;
    public $PubKey;
    public $AddedBy;
    public $ModOn;

    /**
     * Returns the path to a key file.
     * If the file exists in the ajax/ folder, this is bad because it can be accessed by anyone.
     * Move it to the files/ folder, and then return the updated path.
     */
    public static function pathToKey($keyName) {
        global $log;
        $log->debug("Looking for key: $keyName");
        $ajax_dir = __DIR__ . "/../ajax/";
        $files_dir = __DIR__ . "/../../files/";

        if (file_exists("$ajax_dir/$keyName")) {
            $log->warn("Found $keyName in ajax/ folder, moving it to htdocs/files/");
            rename("$ajax_dir/$keyName", "$files_dir/$keyName");
        }

        if (file_exists("$files_dir/$keyName")) {
            return "$files_dir/$keyName";
        }

        $log->error("Could not find keyfile: $keyName");
        return false;
    }

    public static function read_enc_setting()
    {
        $saved_db = DbUtil::switchToGlobal();
        $query_config = "SELECT max(enc_enabled) as enc_enabled from encryption_setting";
        $record = query_associative_one($query_config);
        DbUtil::switchRestore($saved_db);
        return $record['enc_enabled'];
    }

    public static function write_enc_setting($val)
    {
        $saved_db = DbUtil::switchToGlobal();
        $query_config = "update encryption_setting set enc_enabled=".$val;
        query_blind($query_config);
        DbUtil::switchRestore($saved_db);
    }

    public static function getByLabName($labName)
    {
        $saved_db = DbUtil::switchToGlobal();
        $query_config = "SELECT * FROM keymgmt WHERE lab_name ='$labName' LIMIT 1";
        $record = query_associative_one($query_config);
        DbUtil::switchRestore($saved_db);
        return KeyMgmt::getObject($record);
    }

    public static function getById($keyID)
    {
        $saved_db = DbUtil::switchToGlobal();
        $query_config = "SELECT * FROM keymgmt WHERE id =$keyID  LIMIT 1";
        $record = query_associative_one($query_config);
        DbUtil::switchRestore($saved_db);
        return KeyMgmt::getObject($record);
    }

    public static function getAllKeys()
    {
        $saved_db = DbUtil::switchToGlobal();
        $query_configs = "SELECT id,'' as pub_key,lab_name,added_by,last_modified FROM keymgmt ORDER BY lab_name";
        $resultset = query_associative_all($query_configs);
        $retval = array();
        if ($resultset == null) {
            DbUtil::switchRestore($saved_db);
            return $retval;
        }
        foreach ($resultset as $record) {
            $r=KeyMgmt::getObject($record);
            $r->AddedBy=User::getByUserId($r->AddedBy)->username;
            $retval[] = $r;
        }
        DbUtil::switchRestore($saved_db);
        return $retval;
    }

    public static function create($lab_name, $key_text, $user_id) {
        $key = new KeyMgmt();
        $key->LabName = $lab_name;
        $key->PubKey = $key_text;
        $key->AddedBy = $user_id;
        return $key;
    }

    public static function add_key_mgmt($keyMgmt)
    {
        global $log;
        $saved_db = DbUtil::switchToGlobal();
        $query_check = "SELECT count(*) as cnt from keymgmt where lab_name='".$keyMgmt->LabName."'";
        $record = query_associative_one($query_check);
        if ($record['cnt']!=0) {
            return "Key For This Lab Already Exists";
        }
        $query="insert into keymgmt(lab_name,pub_key,added_by,last_modified) values('";
        $query=$query.$keyMgmt->LabName."','".$keyMgmt->PubKey."',".$keyMgmt->AddedBy.",now())";
        $log->debug("Adding public key: $keyMgmt->PubKey");
        query_insert_one($query);
        DbUtil::switchRestore($saved_db);
        return "Key added successfully";
    }

    public static function update_key_mgmt($keyMgmt)
    {
        $saved_db = DbUtil::switchToGlobal();
        $query_check = "SELECT count(*) as cnt from keymgmt where id=".$keyMgmt->ID;
        $record = query_associative_one($query_check);
        if ($record['cnt']<1) {
            return "Lab " . $keyMgmt->ID . " does not exist.";
        }
        $query="update keymgmt set lab_name='";
        $query=$query.$keyMgmt->LabName."',pub_key='".$keyMgmt->PubKey."',added_by=".$keyMgmt->AddedBy.",last_modified=now() where id=".$keyMgmt->ID;
        query_blind($query);
        DbUtil::switchRestore($saved_db);
        return "Key updated successfully.";
    }

    public static function delete_key_mgmt($keyID)
    {
        $saved_db = DbUtil::switchToGlobal();
        $query="delete from keymgmt where id=".$keyID;
        query_blind($query);
        DbUtil::switchRestore($saved_db);
        return "Key deleted successfully.";
    }

    public static function generateKeyPair($privateKeyLocation, $publicKeyLocation) {
        global $log;

        // Configuration for 4096 RSA key Pair with Digest Algo 512
        $config = array(
            "digest_alg" => "sha512",
            "private_key_bits" => 1024,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        );

        if (PlatformLib::runningOnWindows()) {
            $openssl_conf_location = dirname(__FILE__).'/../../server/php/extras/openssl/openssl.cnf';
            $config["config"] = $openssl_conf_location;
        }

        // Create the keypair
        $res=openssl_pkey_new($config);
        if (!$res) {
            $log->error("OpenSSL error: ".openssl_error_string());
            return false;
        }

        // Get private key and write to disk
        openssl_pkey_export($res, $privkey, null, $config);
        file_put_contents($privateKeyLocation, $privkey);

        // Get public key and write to disk
        $pubkey=openssl_pkey_get_details($res);
        file_put_contents($publicKeyLocation, $pubkey["key"]);
    }

    private static function getObject($record)
    {
        if ($record == null) {
            return null;
        }
        $keyMgmt=new KeyMgmt();
        if (isset($record['id'])) {
            $keyMgmt->ID=$record['id'];
            $keyMgmt->LabName=$record['lab_name'];
            $keyMgmt->PubKey=$record['pub_key'];
            $keyMgmt->AddedBy=$record['added_by'];
            $keyMgmt->ModOn=$record['last_modified'];
            return $keyMgmt;
        }
        return null;
    }
}
