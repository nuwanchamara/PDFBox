<?php
/**
 * Created by Imal hasaranga Perera <imal.hasaranga@gmail.com>.
 * Date: 9/5/16
 * Time: 2:33 PM

 * UNEXPECTED ERROR and how to FIX
 * --------------------------------
 * If you are getting this error '**** Unable to open the initial device, quitting.'
 * this means ghostscript is unable to create temporary files while doing some task
 * check the server log to confirm this is the case and then give appropriate permissiongs

 * === you should see something like this in the server logs ===

 * GPL Ghostscript 9.19: **** Could not open temporary file /var/folders/r0/6br2l3h52nzgjtw30g7sdcfw0000gn/T/gs_C8J9yA

*/

namespace ImalH\PDFBox;


class PDFBox{

    public static $MAX_RESOLUTION = 300;
    private $resolution = 0;
    private $jpeg_quality = 100;
    private $page_start = -1;
    private $page_end = -1;
    private $pdf_path = "";
    private $output_path = "";
    private $number_of_pages = -1;

    private $is_os_win = null;
    private $gs_command = null;
    private $gs_version = null;
    private $gs_is_64 = null;
    private $gs_path = null;

    public function __construct(){
        $this->resolution = self::$MAX_RESOLUTION;
        $this->initSystem();
        $gs_version = $this->getGSVersion();
        if($gs_version == -1){
            throw new \Exception("Unable to find GhostScript instalation",1);
        }else if($gs_version < 9.16){
            throw new \Exception("Your version of GhostScript $gs_version is not compatible with  the library", 1);
        }
    }

    public function setPdfPath($pdf_path){
        $this->pdf_path = $pdf_path;
        $this->number_of_pages = -1;
    }

    public function setOutputPath($output_path){
        $this->output_path= $output_path;
    }

    public function setImageQuality($jpeg_quality){
        $this->jpeg_quality = $jpeg_quality;
    }

    public function setPageRange($start, $end){
        $this->page_start = $start;
        $this->page_end = $end;
    }

    public function setDPI($dpi){
        $this->resolution = $dpi;
    }

    public function getNumberOfPages(){
        if($this->number_of_pages == -1){
            $pages = $this->executeGS('-q -dNODISPLAY -c "("'.$this->pdf_path.'") (r) file runpdfbegin pdfpagecount = quit"',true);
            $this->number_of_pages = intval($pages);
        }
        return $this->number_of_pages;
    }



    public function convert(){
        if(!(($this->page_start > 0) && ($this->page_start <= $this->getNumberOfPages()))){
            $this->page_start = 1;
        }

        if(!(($this->page_end <= $this->getNumberOfPages()) && ($this->page_end >= $this->page_start))){
            $this->page_end = $this->getNumberOfPages();
        }

        if(!($this->resolution <= self::$MAX_RESOLUTION)){
            $this->resolution = self::$MAX_RESOLUTION;
        }

        if(!($this->jpeg_quality >= 1 && $this->jpeg_quality <= 100)){
            $this->jpeg_quality = 100;
        }
        $image_path = $this->output_path."/page-%d.jpg";
        $output = $this->executeGS("-dSAFER -dBATCH -dNOPAUSE -sDEVICE=jpeg -r".$this->resolution." -dNumRenderingThreads=4 -dFirstPage=".$this->page_start." -dLastPage=".$this->page_end." -o\"".$image_path."\" -dJPEGQ=".$this->jpeg_quality." -q \"".($this->pdf_path)."\" -c quit");

        $fileArray = [];
        for($i=($this->page_start); $i<=$this->page_end; ++$i){
            $fileArray[] = "page-$i.jpg";
        }

        if(!$this->checkFilesExists($this->output_path,$fileArray)){
            $errrorinfo = implode(",", $output);
            throw new \Exception('PDF_CONVERSION_ERROR '.$errrorinfo);
        }

    }

    public function makePDF($ouput_path_pdf_name, $imagePathArray){
        $imagesources ="";
        foreach ($imagePathArray as $singleImage) {
            $imagesources .= '('.$singleImage.')  viewJPEG showpage ';
        }
        $psfile  = $this->getGSLibFilePath("viewjpeg.ps");
        $command = '-dBATCH -dNOPAUSE -sDEVICE=pdfwrite -o"'.$ouput_path_pdf_name.'" "'.$psfile.'" -c "'.$imagesources.'"';
        $command_results = $this->executeGS($command);
        if(!$this->checkFilesExists("",[$ouput_path_pdf_name])){
            throw new \Exception("Unable to make PDF : ".$command_results[2]);
        }
    }

    public function getGSVersion(){
        return $this->gs_version ? $this->gs_version : -1;
    }

    private function initSystem(){
        $this->is_os_win = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if($this->gs_path == null || $this->gs_version ==null || $this->gs_is_64 == null){
            if($this->is_os_win){
                if(trim($gs_bin_path =  $this->execute("where gswin64c.exe",true)) !="" ){
                    $this->gs_is_64 = true;
                    $this->gs_command = "gswin64c.exe";
                    $this->gs_path = trim(str_replace("bin\\".$this->gs_command, "", $gs_bin_path));
                }else if(trim($gs_bin_path = $this->execute("where gswin32c.exe",true)) !="" ){
                    $this->gs_is_64 = false;
                    $this->gs_command = "gswin32c.exe";
                    $this->gs_path =  trim(str_replace("bin\\".$this->gs_command, "", $gs_bin_path));
                }else{
                    $this->gs_is_64 = null;
                    $this->gs_path = null;
                    die($this->execute("where gswin64c.exe",true));
                }
                if($this->gs_path && $this->gs_command){
                    $output = $this->execute($this->gs_command.' --version 2>&1');
                    $this->gs_version = doubleval($output[0]);
                }
            }else{
                $output = $this->execute('gs --version 2>&1');
                if(!((is_array($output) && (strpos($output[0], 'is not recognized as an internal or external command') !== false)) || !is_array($output) && trim($output) == "")){
                    $this->gs_command = "gs";
                    $this->gs_version = doubleval($output[0]);
                    $this->gs_path = "/usr/local/share/ghostscript/".$this->gs_version;
                    $this->gs_is_64 = "NOT WIN";
                }
            }
        }
    }

    private function execute($command, $is_shell = false){
        $output = null;
        if($is_shell){
            $output = shell_exec($command);
        }else{
            exec($command,$output);
        }
        return $output;
    }

    private function executeGS($command, $is_shell = false){
        return $this->execute($this->gs_command." ".$command,$is_shell);
    }

    private function checkFilesExists($source_path,$fileNameArray){
        foreach ($fileNameArray as $file_name) {
            $source_path = trim($source_path) == "" ? $source_path : $source_path."/";
            if(!file_exists($source_path.$file_name)){
                return false;
            }
        }
        return true;
    }

    private function getGSLibFilePath($filename){
        if($this->is_os_win){
            return $this->gs_path."\\lib\\$filename";
        }else{
            return $this->gs_path."/lib/$filename";
        }
    }
}