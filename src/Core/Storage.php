<?php
namespace App\Core;

abstract class Storage{
  public static function disk($storage,$bucket=''){ 
    if($storage=='local'){
      $adapter = new \League\Flysystem\Local\LocalFilesystemAdapter(dirname(LOCAL_PATH).(strlen($bucket??'')>0?'/'.$bucket:'/files'));
    }elseif($storage=='ftp'){
      $adapter = new \League\Flysystem\Ftp\FtpAdapter(
        \League\Flysystem\Ftp\FtpConnectionOptions::fromArray([
            'host' => FTPHOST,
            'root' => (strlen($bucket??'')>0?$bucket:FTPROOT),
            'username' => FTPUSERNAME,
            'password' => FTPPASSWORD,
            'port' => (FTPSSL?22:21),
            'ssl' => FTPSSL,
            'timeout' => 90,
            'utf8' => false,
            'passive' => true,
            'transferMode' => FTP_BINARY,
            'ignorePassiveAddress' => null, // true or false
            'timestampsOnUnixListingsEnabled' => false, // true or false
            'recurseManually' => true // true 
        ]));
    }elseif($storage=='s3'){
      $clients3 = new \Aws\S3\S3Client(['region' => S3REGION,'version' => 'latest','signature_version' => 'v4','credentials' => ['key'    => S3USERNAME,'secret' => S3PASSWORD,]]);
      $adapter = new \League\Flysystem\AwsS3V3\AwsS3V3Adapter($clients3,(strlen($bucket??'')>0?$bucket:S3BUCKET));  
    }
    return new FileSystem($adapter);
  }
}

class FileSystem
{
  private $filesystem;
  private $visibility = 'public';
  public function __construct($adapter){ 
    $this->filesystem = new \League\Flysystem\Filesystem($adapter);
  }

  public function list($path,$recursive=false){
    $items=[];
    $detector = new \League\MimeTypeDetection\ExtensionMimeTypeDetector();
    $allPaths = $this->filesystem->listContents($path,$recursive);
    foreach ($allPaths as $item) {      
      if($item['type']=='file'){     
        $items[]=[
          'type'=>'file',
          'path'=>$item['path'],
          'name'=>str_replace($path.'/','',$item['path']),
          'size'=>$item['fileSize'],
          'timestamp'=>$item['lastModified'],
          'mimetype'=>$detector->detectMimeTypeFromPath($item['path']),
        ];
      }else{
        $items[]=[
          'type'=>'dir',
          'path'=>$item['path'],
          'name'=>str_replace($path.'/','',$item['path']),
          'timestamp'=>$item['lastModified'],
        ];
      }
    }
    return $items;
  }

  public function listfolders($path,$recursive=false){
    return array_filter($this->list($path,$recursive),function ( $item){ return $item["type"]=='dir';});
  }

  public function listfiles($path,$recursive=false){
    return array_filter($this->list($path,$recursive),function ( $item){ return $item["type"]=='file';});
  }

  public function exists($path){
    return $this->isfile($path) || $this->isfolder($path);
  } 

  public function isfile($path){
    return $this->filesystem->fileExists($path);
  }

  public function isfolder($path){
    return $this->filesystem->directoryExists ($path);
  }

  public function download($path){
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.basename($path).'"');
    echo $this->filesystem->read($path);
    die;
  }

  public function createfolder($path){
    try {
      $this->filesystem->createDirectory($path,['visibility' => 'public']);
      return true;
    } catch (\Exception $e) {
      // handle the file exists exception
      if($GLOBALS["debugbar"] ?? null) $GLOBALS["debugbar"]['exceptions']->addException($e);
      return false;
    }
  } 


  public function write($path,$contents){
    try {
      $this->filesystem->write($path, $contents,['visibility' => 'public']);
    } catch (\Exception $e) {
      // handle the file exists exception
      return $e;
    }
  }

  public function writestream($path,$stream){
    try {
      $this->filesystem->writeStream($path, $stream,['visibility' => 'public']);
      if (is_resource($stream)) {
        fclose($stream);
      }
    } catch (\Exception $e) {
      if($GLOBALS["debugbar"] ?? null) $GLOBALS["debugbar"]['exceptions']->addException($e);
      return false;
    }
  }

  public function read($path){
    try {
      return $this->filesystem->read($path);
    } catch (\Exception $e) {
      if($GLOBALS["debugbar"] ?? null) $GLOBALS["debugbar"]['exceptions']->addException($e);
      return false;
    }
  }

  public function delete($path){
    try {

      if( $this->exists($path) ){
        if($this->isfolder($path))
          $this->filesystem->deleteDirectory($path);
        else
        $this->filesystem->delete($path);

      }elseif( $this->exists(urldecode($path)) ){
        if($this->isfolder(urldecode($path)))
          $this->filesystem->deleteDirectory(urldecode($path));
        else
          $this->filesystem->delete(urldecode($path));

      }else{
        throw new \Exception("El archivo o carpeta no existe");
      }

      return true;


    } catch (\Exception $e) {
      var_export($e);die;
      if($GLOBALS["debugbar"] ?? null) $GLOBALS["debugbar"]['exceptions']->addException($e);
      return false;
    }
  }

  public function copy($path,$newpath){
    try {
      return $this->filesystem->copy($path,$newpath);
    } catch (\Exception $e) {
      if($GLOBALS["debugbar"] ?? null) $GLOBALS["debugbar"]['exceptions']->addException($e);
      return false;
    }
  }

  public function move($path,$newpath){
    try { return $this->filesystem->move($path,$newpath);
    } catch (\Exception $e) {
      if($GLOBALS["debugbar"] ?? null) $GLOBALS["debugbar"]['exceptions']->addException($e);
      return false;
     }
  }

  public function rename($path,$newpath){
    try { 
      $this->filesystem->copy($path,$newpath);
      $this->filesystem->delete($path);
      return true;
    } catch (\Exception $e) {
      if($GLOBALS["debugbar"] ?? null) $GLOBALS["debugbar"]['exceptions']->addException($e);
      return false;
    }
  }


}