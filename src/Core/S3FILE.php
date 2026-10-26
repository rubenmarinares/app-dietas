<?php
namespace App\Core;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

abstract class S3FILE
{
  static function save($file,$name,$ruta=''){
    if($file=='/tmp/'.basename($file)) $file='/tmp/'.basename($file);
	  try {
      $s3Client = new S3Client([
            'region'            => S3REGION,
            'version'           => 'latest',
            'signature_version' => 'v4',
            'credentials' => [
                    'key'    => S3USERNAME,
                    'secret' => S3PASSWORD,
                  ]
        ]);
      $result = $s3Client->putObject(array(
          'Bucket' => S3BUCKET,
          'Key'    => $ruta.$name,
          'SourceFile'   =>  $file,
          'ContentType' =>mime_content_type($file)
      ));
      if($result) return true;
    } catch (S3Exception $e) {
        return false;
    } catch (AwsException $e) {
        return false;
    }	
  }
  static function getobjet($name,$ruta=''){
    try {
      $s3Client = new S3Client([
            'region'            => S3REGION,
            'version'           => 'latest',
            'signature_version' => 'v4',
            'credentials' => [
                    'key'    => S3USERNAME,
                    'secret' => S3PASSWORD,
                  ]
        ]);
  
      $result = $s3Client->getObject(array(
          'Bucket' => S3BUCKET,
          'Key'    => $ruta.$name,
      ));
      if($result) return $result;
    } catch (S3Exception $e) {
        return false;
    } catch (AwsException $e) {
        return false;
    }	
  }

  

  static function download($name,$ruta=''){
    $obj=self::getobjet($name,$ruta);
    header("Content-Type: ".$obj['ContentType']."");
    header('Content-Disposition: attachment; filename="' . basename($name) . '"');
    header('Content-Length: ' . $obj['ContentLength']);
    echo $obj['Body'];
    die;
  }
  static function view($name,$ruta=''){
    $obj=self::getobjet($name,$ruta);
    header("Content-Type: ".$obj['ContentType']."");
    header('Content-Disposition: inline; filename="' . basename($name) . '"');
    header('Content-Length: ' . $obj['ContentLength']);
    echo $obj['Body'];
    die;
  }
  static function content($name,$ruta=''){
    $obj=self::getobjet($name,$ruta);
    return $obj['Body'];
  }
}


