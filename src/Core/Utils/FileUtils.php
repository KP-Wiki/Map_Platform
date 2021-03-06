<?php
    /**
     * Generic file utilities
     *
     * PHP version 7
     *
     * @package    MapPlatform
     * @subpackage Core\Utils
     * @author     Thimo Braker <thibmorozier@gmail.com>
     * @version    1.0.0
     * @since      First available since Release 1.0.0
     */
    namespace MapPlatform\Core\Utils;

    use \Slim\Container;
    use \MatthiasMullie\Minify;
    use \Imagick;
    use \InvalidArgumentException;

    /**
     * File utilities
     *
     * @package    MapPlatform
     * @subpackage Core\Utils
     * @author     Thimo Braker <thibmorozier@gmail.com>
     * @version    1.0.0
     * @since      First available since Release 1.0.0
     */
    final class FileUtils
    {
        /** @var \Slim\Container $container The framework container */
        private $container;

        /**
         * FileUtils constructor.
         *
         * @param \Slim\Container The application controller.
         */
        public function __construct(Container &$aContainer)
        {
            $this->container = $aContainer;
        }

        /**
         * Get the text inside of a file.
         *
         * @param string $aFile
         *
         * @return string
         */
        public function getFileText($aFile)
        {
            $fileHandle = fopen($aFile, 'r');
            $result = '';

            if ($fileHandle !== false) {
                while ($line = fgets($fileHandle)) {
                    $result .= $line;
                }

                fclose($fileHandle);
            }

            return $result;
        }

        /**
         * Perform the main actions on the files and determine if it should/can be minified.
         *
         * @param array $arr
         * @param string $type
         */
        private function minify($arr, $type)
        {
            $settings = $this->container->get('settings')['minifier'];
            $hashArr = file_exists($settings['hashFile']) ? json_decode(file_get_contents($settings['hashFile']), true) : [];

            foreach ($arr as $inFile => $outFile) {
                // Skip the file if the source does not exist
                if (!file_exists($inFile)) {
                    $this->container->logger->err("Error: File '" . $inFile . "' does not exist, Skipping.");
                    continue;
                }

                // Skip the file if the source hashes are equal
                if (array_key_exists($inFile, $hashArr) && $hashArr[$inFile] == sha1(file_get_contents($inFile)))
                    continue;

                $writeHandle = fopen($outFile, 'w');

                // Skip the file if the destination can not be opened for writing
                if (!$writeHandle) {
                    $this->container->logger->err("Error: Unable to open file '" . $outFile . "', Skipping.");
                    continue;
                }

                if ($type == 'CSS') {
                    $minifier = new Minify\CSS($inFile);
                } elseif ($type == 'JS') {
                    $minifier = new Minify\JS($inFile);
                } else { // Completely ignore bad types
                    continue;
                }

                fwrite($writeHandle, $minifier->minify());
                fclose($writeHandle);
                $hashArr[$inFile] = sha1(file_get_contents($inFile));
                $this->container->logger->info("Successfully minified file '" . $inFile . "' to '" . $outFile . "'.");
            }

            file_put_contents($settings['hashFile'], json_encode($hashArr, JSON_PRETTY_PRINT));
        }

        /**
         * Minify all JS files in the array.
         *
         * @param array $arr
         */
        public function minifyJS($arr)
        {
            $this->minify($arr, 'JS');
        }

        /**
         * Minify all CSS files in the array.
         *
         * @param array $arr
         */
        public function minifyCSS($arr)
        {
            $this->minify($arr, 'CSS');
        }

        /**
         * Resize images to specified size
         *
         * @param &\Imagick\Imagick The image as an Imagick object
         * @param int New image width
         * @param int New image height
         */
        public function resizeImage(&$imageObject, $maxWidth, $maxHeight)
        {
            $format = $imageObject->getImageFormat();

            if ($format == 'GIF') { // If it's a GIF file we need to resize each frame one by one
                $imageObject = $imageObject->coalesceImages();

                foreach ($imageObject as $frame) { // Gaussian seems better for animations
                    $frame->resizeImage($maxWidth , $maxHeight , Imagick::FILTER_GAUSSIAN, 1, true);
                }

                $imageObject = $imageObject->deconstructImages();
            } else { // Lanczos seems better for static images
                $imageObject->resizeImage($maxWidth , $maxHeight , Imagick::FILTER_LANCZOS, 1, true);
            }
        }

        /**
         * Re-arrange file array to usable format
         *
         * @param &array File array to re-arrange
         * @return array Re-arranged array
         */
        public function reArrayFiles(&$aFileArr)
        {
            $resultArr = [];
            $fileCount = count($aFileArr['name']);
            $fileKeys = array_keys($aFileArr);

            for ($i = 0; $i < $fileCount; $i++) {
                foreach ($fileKeys as $key) {
                    $resultArr[$i][$key] = $aFileArr[$key][$i];
                }
            }

            return $resultArr;
        }

        /**
         * Recursively create directories
         *
         * @param string $path The dir path
         * @param string $base The base
         */
        function mkdirRecursive($path, $base)
        {
            $path = str_replace("\\", '/', $path);
            $path = explode('/', $path);
            $base = str_replace("\\", '/', $base);
            $rebuild = $base;

            foreach ($path as $p) {
                $rebuild .= '/' . $p;
                $rebuild = str_replace("//", '/', $rebuild);

                if (!is_dir($rebuild))
                    mkdir($rebuild);
            }
        }
    }
