<?php

namespace Iphp\FileStoreBundle\FileStorage;

use Iphp\FileStoreBundle\File\FileInterface;
use Iphp\FileStoreBundle\File\LocalFileInterface;
use Iphp\FileStoreBundle\File\UploadedFileInterface;
use Iphp\FileStoreBundle\FileStorage\FileStorageInterface;
use Iphp\FileStoreBundle\Mapping\PropertyMapping;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\File\File;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;

/**
 * FileSystemStorage.
 *
 * @author Vitiko <vitiko@mail.ru>
 */
class FileSystemStorage implements FileStorageInterface
{

    protected $webDir;

    protected $sameFileChecker;

    /**
     * Constructs a new instance of FileSystemStorage.
     *
     * @param
     */
    public function __construct($webDir = null)
    {
        $this->webDir = $webDir;


        // @codeCoverageIgnoreStart
        $this->sameFileChecker = function (File $file, $fullFileName)
        {
            return $file->getRealPath() == realpath($fullFileName);
        };
        // @codeCoverageIgnoreEnd
    }

    public function setWebDir($webDir )
    {
        $this->webDir = $webDir;
    }

    public function getWebDir()
    {
        return $this->webDir;
    }

    public function setSameFileChecker (\Closure $checker)
    {
        $this->sameFileChecker = $checker;
    }


    public function   isSameFile (File $file,  $fullFileName)
    {
        return  call_user_func(
            $this->sameFileChecker,
            $file,
            $fullFileName);

    }


    protected function copyFile($source, $directory, $name)
    {
        $this->checkDirectory($directory);
        $target = $directory . DIRECTORY_SEPARATOR . basename($name);

        if (!@copy($source, $target)) {
            $error = error_get_last();
            throw new FileException(sprintf('Could not copy the file "%s" to "%s" (%s)', $source, $target, strip_tags($error['message'])));
        }

        @chmod($target, 0666 & ~umask());

        return new File($target);
    }







    protected function checkDirectory ($directory)
    {
        if (!is_dir($directory)) {
            if (false === @mkdir($directory, 0777, true)) {

                // @codeCoverageIgnoreStart
                throw new FileException(sprintf('Unable to create the "%s" directory', $directory));
                // @codeCoverageIgnoreEnd
            }
        } elseif (!is_writable($directory)) {
            // @codeCoverageIgnoreStart
            throw new FileException(sprintf('Unable to write in the "%s" directory', $directory));
            // @codeCoverageIgnoreEnd
        }

        return true;
    }




    public function saveLocalFile(PropertyMapping $mapping, File $file)
    {
        $originalName = $file instanceof FileInterface && $file->getOriginalName() ?
            $file->getOriginalName() :  $file->getFilename();
        $mimeType = $file->getMimeType();

        $fileProtected = $file instanceof FileInterface && $file->isProtected();

        //transform filename and directory name if namer exists in mapping definition
        list ($fileName, $webPath) = $mapping->prepareFileName($originalName, $this,     $fileProtected );

        $fullFileName = $mapping->resolveFileName($fileName,  $fileProtected );


        //check if file already placed in needed position
        if (!$this->isSameFile($file, $fullFileName)) {
            $fileInfo = pathinfo($fullFileName);
            $this->checkDirectory($fileInfo['dirname']);

            if ( $file instanceof FileInterface && $file->getSaveSource() == false)   $file->move($fileInfo['dirname'], $fileInfo['basename']); else
            {
                $this->copyFile($file->getPathname(), $fileInfo['dirname'], $fileInfo['basename']);
            }
        }

        return $this->prepareFileData($fileName, $originalName, $mimeType, $fullFileName, $webPath,  $fileProtected);
    }


    public function saveUploadedFile(PropertyMapping $mapping, UploadedFile   $file)
    {
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getClientMimeType();

        //transform filename and directory name if namer exists in mapping definition
        list ($fileName, $webPath) = $mapping->prepareFileName($originalName, $this);

        $fileProtected = $file instanceof UploadedFileInterface && $file->isProtected();

        $fullFileName = $mapping->resolveFileName($fileName,  $fileProtected );


        $fileInfo = pathinfo($fullFileName);

        $this->checkDirectory($fileInfo['dirname']);
        $file->move($fileInfo['dirname'], $fileInfo['basename']);


        return $this->prepareFileData($fileName, $originalName, $mimeType, $fullFileName, $webPath,  $fileProtected);
    }



    protected function prepareFileData ($fileName, $originalName, $mimeType, $fullFileName, $webPath,  $protected)
    {
        $fileData = array(
            'fileName' => $fileName,
            'originalName' => $originalName,
            'mimeType' => $mimeType,
            'size' => filesize($fullFileName),
            'path' => $webPath,
            'protected' => $protected ? true : false
        );

        if (!$fileData['path'])
            $fileData['path'] = substr($fullFileName, strlen($this->webDir));


        $ext = substr($originalName,strrpos ($originalName,'.')+1);

        if ((in_array($fileData['mimeType'], array('image/png', 'image/jpeg', 'image/pjpeg')) ||
                in_array ($ext,array ('jpeg','jpg','png')))
            && function_exists('getimagesize')
        ) {
            list($width, $height, $type) = @getimagesize($fullFileName);
            $fileData = array_merge($fileData, array(
                'width' => $width, 'height' => $height
            ));
        }

        return $fileData;
    }


    /**
     * {@inheritDoc}
     * File may be \Symfony\Component\HttpFoundation\File\File or \Symfony\Component\HttpFoundation\File\UploadedFile
     */
    public function saveFile (PropertyMapping $mapping,  File $file)
    {
        if ($file instanceof UploadedFile ) return $this->saveUploadedFile($mapping, $file);
        else return $this->saveLocalFile($mapping, $file);
    }


    /**
     *  {@inheritDoc}
     */
    public function removeFile($fullFileName)
    {

        if ($fullFileName && file_exists($fullFileName)) {
            @unlink($fullFileName);
            return !file_exists($fullFileName);
        }
        return null;
    }


    /**
     *  {@inheritDoc}
     */
    public function fileExists($fullFileName)
    {
        return file_exists($fullFileName);
    }
}
