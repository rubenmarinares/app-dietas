<?php
namespace App\Core;
use \Mpdf\Mpdf;

abstract class PDF
{
  static function print($html='', $filepath=''){
    $mpdf = new Mpdf([
      'margin_left' => 20,
      'margin_right' => 15,
      'margin_top' => 38,
      'margin_bottom' => 25,
      'margin_header' => 10,
      'margin_footer' => 10
    ]);
    
//    $mpdf->showImageErrors = true;
    $mpdf->SetTitle("");
    $mpdf->SetAuthor(APP_NAME);

    $mpdf->SetDisplayMode('fullpage');
    
    $mpdf->WriteHTML($html);
    if($filepath!=''){
      $mpdf->Output($filepath, 'F'); //,'F' save to file
    }else
      $mpdf->Output();
  }

}


