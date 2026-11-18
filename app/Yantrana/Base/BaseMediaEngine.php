<?php
/**
 * WhatsJet
 *
 * This file is part of the WhatsJet software package developed and licensed by livelyworks.
 *
 * You must have a valid license to use this software.
 *
 * © 2024 - 2026 livelyworks. All rights reserved.
 * Redistribution or resale of this file, in whole or in part, is prohibited without prior written permission from the author.
 *
 * For support or inquiries, contact: contact@livelyworks.net
 *
 * @package     WhatsJet
 * @author      livelyworks <contact@livelyworks.net>
 * @copyright   Copyright (c) 2024 - 2026 livelyworks
 * @website     https://livelyworks.net
 */


/**
 * BaseMediaEngine.php - Base Media Engine file
 *
 * This file is part of the Media component.
 *-----------------------------------------------------------------------------*/

namespace App\Yantrana\Base;

use YesFileStorage;
use ImageIntervention;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Filesystem\Filesystem;

class BaseMediaEngine extends BaseEngine
{
    protected $elements;

    protected $currentDisk;

    protected $disk;

    /**
     * Constructor.
     *
     * @param  MediaRepository  $mediaRepository  - Media Repository
     *-----------------------------------------------------------------------*/
    public function __construct()
    {
        $this->currentDisk = config('filesystems.default', 'public-media-storage'); //configItem('current_filesystem_driver');
        $this->disk = YesFileStorage::on($this->currentDisk); // do_s3_space, local
        $this->elements = config('yes-file-storage.element_config');
    }

    /**
     * Upload all files
     *
     * @param  array  $input
     * @param  number  $allowedExtensions
     * @return EngineResponse
     *-----------------------------------------------------------------------*/
    public function processUpload($input, $folderPath, $requestFor = '', $storeAsPublic = true, $deleteExisting = null)
    {
        try {
            // delete old files older than 1 hours
            $tempFolderPath = getPathByKey('user_temp_uploads', ['{_uid}' => authUID()]);
            $this->deleteOldFiles($tempFolderPath);

            $file = $input['filepond'] ?? null;

            if (!($file instanceof UploadedFile)) {
                if (request()->hasFile('filepond')) {
                    $file = request()->file('filepond');
                } elseif (!empty(request()->allFiles())) {
                    $allFiles = request()->allFiles();
                    $firstFile = reset($allFiles);
                    if (is_array($firstFile)) {
                        $firstFile = reset($firstFile);
                    }
                    if ($firstFile instanceof UploadedFile) {
                        $file = $firstFile;
                    }
                }
            }

            if (is_string($file)) {
                $decoded = json_decode($file, true);
                if (is_array($decoded) && isset($decoded['data'])) {
                    $fileData = $decoded['data'];
                    $originalName = $decoded['name'] ?? ('upload-'.uniqid().'.jpg');
                    if (str_contains($fileData, 'base64,')) {
                        $fileData = explode('base64,', $fileData)[1];
                    }
                    $tempPath = tempnam(sys_get_temp_dir(), 'filepond_');
                    file_put_contents($tempPath, base64_decode($fileData));
                    $file = new UploadedFile($tempPath, $originalName, $decoded['type'] ?? null, null, true);
                } elseif (str_starts_with($file, 'data:') && str_contains($file, 'base64,')) {
                    $parts = explode('base64,', $file);
                    $tempPath = tempnam(sys_get_temp_dir(), 'filepond_');
                    file_put_contents($tempPath, base64_decode($parts[1]));
                    $file = new UploadedFile($tempPath, 'upload-'.uniqid().'.jpg', null, null, true);
                } elseif (is_file($file)) {
                    $file = $this->uploadedFileInstance($file);
                } else {
                    $this->disk = YesFileStorage::on('public-media-storage');
                    $userTempPath = getPathByKey('user_temp_uploads', ['{_uid}' => authUID()]);
                    $filePath = $userTempPath.DIRECTORY_SEPARATOR.$file;
                    if ($this->disk->isExists($filePath)) {
                        $file = $this->uploadedFileInstance($this->disk->getStoragePath($filePath));
                    }
                    $this->disk = YesFileStorage::on($this->currentDisk);
                }
            }

            if (!($file instanceof UploadedFile)) {
                return $this->engineFailedResponse(['show_message' => true], __tr('Uploaded file does not exists or is invalid.'));
            }

            $fileOriginalName = $file->getClientOriginalName();
            $fileExtension = $file->getClientOriginalExtension();
            $fileMimeType = $file->getClientMimeType();
            $fileBaseName = Str::slug(basename($fileOriginalName, '.'.$fileExtension));
            $fileName = uniqid().'---'.$fileBaseName.".$fileExtension";

            // if requested $requestFor not present in $this->elements
            // Then it return invalid request message.
            if (! array_has($this->elements, $requestFor)) {
                return $this->engineFailedResponse([], __tr('Something went wrong.'));
            }

            $restrictions = $this->elements[$requestFor]['restrictions'];
            $allowedFileTypes = $restrictions['allowedFileTypes'];

            // Check restrictions of file
            $allowedFileExtensions = $restrictions['allowedFileExtensions'] ?? [];
            if(!empty($allowedFileExtensions)) {
                if (! in_array($fileExtension, $allowedFileExtensions)) {
                    return $this->engineFailedResponse(['show_message' => true], __tr('Only __ex__ accepted.', [
                        '__ex__' => implode(', ', $allowedFileExtensions),
                    ]));
                }
            }
            if (! in_array($fileMimeType, $allowedFileTypes)) {
                return $this->engineFailedResponse([], __tr('Only __ex__ accepted', [
                    '__ex__' => implode(', ', $allowedFileTypes),
                ]));
            }

            // If not exists then folder then create
            try {
                if ($this->disk->isExists($folderPath) === false) {
                    // create temp file folder
                    $this->disk->createFolder($folderPath);
                }
            } catch (\Throwable $e) {
                // S3 / object storage does not require folder pre-creation
            }

            // Store file on destination
            if ($this->disk->storeFileAs($folderPath, $file, $fileName)) {
                if ($storeAsPublic) {
                    try {
                        $this->disk->setFileAccessType($folderPath.DIRECTORY_SEPARATOR.$fileName, 'public');
                    } catch (\Throwable $e) {
                        // S3-compatible providers handle ACL at bucket level
                    }
                }

                if ($deleteExisting and $this->disk->isExists($folderPath.DIRECTORY_SEPARATOR.$deleteExisting)) {
                    $this->delete($folderPath, $deleteExisting);
                }

                return $this->engineSuccessResponse([
                    'path' => getMediaUrl($folderPath, $fileName),
                    'original_filename' => $fileOriginalName,
                    'fileName' => $fileName,
                    'fileMimeType' => $fileMimeType,
                    'fileExtension' => $fileExtension,
                    'realPath' => $folderPath,
                ], __tr('File uploaded successfully.'));
            }

            return $this->engineFailedResponse([], __tr('Something went wrong, Please try again.'));

            // catch exception / error
        } catch (\Throwable $e) {
            return $this->engineFailedResponse([], $e->getMessage());
        }
    }

    /**
     * Process store favicon media.
     *
     * @param  string  $logoImageFile
     * @return EngineResponse
     *---------------------------------------------------------------- */
    public function processMoveFile($destinationPath, $fileName, $resizeOptions = [], $options = [])
    {
        $options = array_merge([
            'setVisibility' => null, // public/private
            'publicMediaStorage' => true,
            'pathParameters' => [
                '{_uid}' => authUID(),
            ],
        ], $options);
        try {
            if($options['publicMediaStorage']) {
                $this->disk = YesFileStorage::on('public-media-storage');
            }
            $tempFolderPath = getPathByKey('user_temp_uploads', $options['pathParameters']);
            // Check exists of file
            $tempFullPath = $tempFolderPath.DIRECTORY_SEPARATOR.$fileName;
            if ($this->disk->isExists($tempFullPath) === false && !file_exists(public_path($tempFullPath))) {
                return $this->engineFailedResponse([], __tr('File does not exists.'));
            }

            // full source path
            $sourcePath = $tempFolderPath.DIRECTORY_SEPARATOR.$fileName;

            // Check if media directory exist
            try {
                if ($this->disk->isExists($destinationPath) === false) {
                    // create temp file folder
                    $this->disk->createFolder($destinationPath);
                }
            } catch (\Throwable $e) {
                // S3 / object storage does not require folder pre-creation
            }
            $this->disk = YesFileStorage::on($this->currentDisk);
            // If source moved to destination
            if ($this->disk->moveFile($sourcePath, $destinationPath.'/'.$fileName)) {
                if (! __isEmpty($resizeOptions)) {
                    $this->resizeImageAndUpload($destinationPath, $fileName, $resizeOptions);
                }
                if($options['setVisibility']) {
                    $this->disk->setFileAccessType($destinationPath.'/'.$fileName, $options['setVisibility']);
                }
                // return file preview url and file name
                return $this->engineSuccessResponse([
                    'path' => getMediaUrl($destinationPath, $fileName),
                    'fileName' => $fileName,
                ]);
            }
        } catch (Exception $e) {
            return $this->engineFailedResponse([], __tr('Something went wrong while move file.'));
        }
    }

    /**
     * Delete file
     *
     * @return array
     *---------------------------------------------------------------- */
    public function downloadFile($uploadItemKey, $filename, $uploadKeyOptions = [])
    {
        $file = getPathByKey($uploadItemKey, $uploadKeyOptions).DIRECTORY_SEPARATOR.$filename;
        abortIf(! $this->disk->isExists($file), 404, __tr('File not found'));

        return $this->disk->downloadFile($file);
    }

    /**
     * Delete file
     *
     * @return array
     *---------------------------------------------------------------- */
    public function deleteFile($uploadItemKey, $filename, $uploadKeyOptions = [])
    {
        return $this->delete(
            getPathByKey($uploadItemKey, $uploadKeyOptions),
            $filename
        );
    }

    /**
     * Delete file
     *
     * @return array
     *---------------------------------------------------------------- */
    public function delete($destinationPath, $filename = null, $additionalOptions = [])
    {
        try {
            if ($filename) {
                $destinationPath .= DIRECTORY_SEPARATOR.$filename;
            }

            // Delete existing file
            if ($this->disk->isExists($destinationPath)) {
                if ($this->disk->deleteFile($destinationPath)) {
                    if (
                        isset($additionalOptions['thumbnail_space_path'])
                        and ! __isEmpty($additionalOptions['thumbnail_space_path'])
                    ) {
                        $thumbnailSpacePath = array_get($additionalOptions, 'thumbnail_space_path');
                        if ($this->disk->isExists($thumbnailSpacePath.'/'.$filename)) {
                            $this->disk->deleteFile($thumbnailSpacePath.'/'.$filename);
                        }
                    }

                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Process Upload Temp Media
     *
     * @return array|object
     *---------------------------------------------------------------- */
    public function processUploadTempMedia($inputFile, $requestFor)
    {
        $tempUploadFolderPath = getPathByKey('user_temp_uploads', ['{_uid}' => authUID()]);
        $this->disk = YesFileStorage::on('public-media-storage');
        $processUploadResult = $this->processUpload($inputFile, $tempUploadFolderPath, $requestFor);
        $this->disk = YesFileStorage::on($this->currentDisk);

        // Also sync temp file to cloud storage (S3) so the user immediately sees it in the bucket
        // and so it survives Render container restarts!
        if ($processUploadResult->success() && $this->currentDisk !== 'public-media-storage') {
            try {
                $fileName = $processUploadResult->data('fileName');
                $localPath = public_path($tempUploadFolderPath . DIRECTORY_SEPARATOR . $fileName);
                if (file_exists($localPath)) {
                    $this->disk->storeFileAs($tempUploadFolderPath, $localPath, $fileName);
                }
            } catch (\Throwable $e) {
                // Non-fatal sync
            }
        }

        return $processUploadResult;
    }
    /**
     * Process Upload Temp Addon
     *
     * @return array|object
     *---------------------------------------------------------------- */
    public function processAddonUploadTemp($inputFile, $requestFor)
    {
        $tempUploadFolderPath = getPathByKey('internal_temp');
        $this->disk = YesFileStorage::on('local');
        $processUploadResult = $this->processUpload($inputFile, $tempUploadFolderPath, $requestFor);
        $this->disk = YesFileStorage::on($this->currentDisk);
        return $processUploadResult;
    }

    /**
     * Create an UploadedFile object from absolute path
     *
     * @param  string  $path
     * @param  bool  $test  default true
     * @return object(Illuminate\Http\UploadedFile)
     *
     * Based of Alexandre Thebaldi answer here:
     * https://stackoverflow.com/a/32258317/6411540
     */
    public function uploadedFileInstance($path, $test = true)
    {
        $filesystem = new Filesystem();
        $name = $filesystem->name($path);
        $extension = $filesystem->extension($path);
        $originalName = $name.'.'.$extension;
        $mimeType = $filesystem->mimeType($path);
        $error = null;

        return new UploadedFile($path, $originalName, $mimeType, $error, $test);
    }

    /**
     * Process Upload File on local server
     *
     * @return array|object
     *---------------------------------------------------------------- */
    public function processUploadFileOnLocalServer($input, $allowedExtension = '')
    {
        if (is_string($input)) {
            $input = [
                'filepond' => $input,
            ];
        }
        // if request file not found it will throw error.
        if (! array_has($input, 'filepond') && __isEmpty($input['filepond'])) {
            return $this->engineFailedResponse(['show_message' => true], __tr('Uploaded file does not exists'));
        }

        $uploadedFile = $input['filepond'];

        if (is_string($uploadedFile)) {
            $this->disk = YesFileStorage::on('public-media-storage');
            $path = getPathByKey('user_temp_uploads', ['{_uid}' => authUID()]);
            $filePath = $path.DIRECTORY_SEPARATOR.$uploadedFile;

            $fileExists = false;
            try {
                $fileExists = $this->disk->isExists($filePath);
            } catch (\Throwable $e) {
                $fileExists = false;
            }

            if (!$fileExists && file_exists(public_path($filePath))) {
                $fileExists = true;
            }

            // If not found locally (e.g. Render redeployed or restarted container),
            // restore the temp file from S3 bucket!
            if (!$fileExists && $this->currentDisk !== 'public-media-storage') {
                try {
                    $cloudDisk = YesFileStorage::on($this->currentDisk);
                    if ($cloudDisk->isExists($filePath)) {
                        $fileContents = $cloudDisk->getFileIfExists($filePath);
                        if ($fileContents) {
                            $localDir = public_path($path);
                            if (!File::isDirectory($localDir)) {
                                File::makeDirectory($localDir, 0777, true, true);
                            }
                            file_put_contents(public_path($filePath), $fileContents);
                            $fileExists = true;
                        }
                    }
                } catch (\Throwable $e) {
                    $fileExists = false;
                }
            }

            if (! $fileExists) {
                return $this->engineFailedResponse(['show_message' => true], __tr('Temporary file expired or not found, please select the image again.'));
            }

            $storagePath = null;
            try {
                $storagePath = $this->disk->getStoragePath($filePath);
            } catch (\Throwable $e) {
                $storagePath = public_path($filePath);
            }

            if (!$storagePath || !file_exists($storagePath)) {
                $storagePath = public_path($filePath);
            }

            $uploadedFile = $this->uploadedFileInstance($storagePath);
            $this->disk = YesFileStorage::on($this->currentDisk);
        }

        // Check if file __isEmpty or is valid
        if (__isEmpty($uploadedFile) or ! $uploadedFile->isValid()) {
            return $this->engineFailedResponse(['show_message' => true], __tr('Invalid or Missing uploaded file'));
        }

        $fileOriginalName = $uploadedFile->getClientOriginalName();
        $fileExtension = $uploadedFile->getClientOriginalExtension();
        $fileMimeType = $uploadedFile->getClientMimeType();
        $fileBaseName = str_slug(basename($fileOriginalName, '.'.$fileExtension));
        $fileName = $fileBaseName.'-'.uniqid().'.'.$fileExtension;

        $restrictions = $this->elements[$allowedExtension]['restrictions'];
        $allowedFileTypes = $restrictions['allowedFileTypes'];
        // Check restrictions of file
        $allowedFileExtensions = $restrictions['allowedFileExtensions'] ?? [];
        if(!empty($allowedFileExtensions)) {
            if (! in_array($fileExtension, $allowedFileExtensions)) {
                return $this->engineFailedResponse(['show_message' => true], __tr('Only __ex__ accepted.', [
                    '__ex__' => implode(', ', $allowedFileExtensions),
                ]));
            }
        }
        if (! in_array($fileMimeType, $allowedFileTypes)) {
            return $this->engineFailedResponse(['show_message' => true], __tr('Only __ex__ accepted.', [
                '__ex__' => implode(', ', $allowedFileTypes),
            ]));
        }

        $path = getPathByKey('user_temp_uploads', ['{_uid}' => authUID()]);
        $targetDir = public_path($path);

        if (! File::isDirectory($targetDir)) {
            File::makeDirectory($targetDir, $mode = 0777, true, true);
        }

        $targetFilePath = $targetDir . DIRECTORY_SEPARATOR . $fileName;
        $moved = false;
        try {
            if ($uploadedFile->move($targetDir, $fileName)) {
                $moved = true;
            }
        } catch (\Throwable $e) {
            if (isset($storagePath) && file_exists($storagePath)) {
                $moved = File::copy($storagePath, $targetFilePath);
                if ($moved) {
                    @File::delete($storagePath);
                }
            }
        }

        if ($moved || file_exists($targetFilePath)) {
            return $this->engineSuccessResponse([
                'fileExtension' => $fileExtension,
                'fileMimeType' => $fileMimeType,
                'fileName' => $fileName,
                'show_message' => true,
            ], __tr('File Uploaded Successfully.'));
        }

        return $this->engineFailedResponse(['show_message' => true], __tr('Something went wrong while file uploading.'));
    }

    /**
     * Resize image and upload on server
     *
     * @return array
     *---------------------------------------------------------------- */
    public function resizeImageAndUpload($destinationPath, $fileName, $options = [])
    {
        $path = getPathByKey('user_temp_uploads', ['{_uid}' => authUID()]);

        // create path to thumbnail (resolve to absolute public_path if available)
        $localFileDestination = public_path($path.'/'.$fileName);
        if (!File::exists($localFileDestination) && File::exists($path.'/'.$fileName)) {
            $localFileDestination = $path.'/'.$fileName;
        }

        $fileMimeType = File::exists($localFileDestination) ? File::mimeType($localFileDestination) : 'image/jpeg';
        $options = array_merge([
            'resize' => null,
        ], $options);
        if ($options['resize'] && File::exists($localFileDestination)) {

            // open an image file
            $thumbnail = ImageIntervention::make($localFileDestination);

            $width = $options['width'];
            $height = $options['height'];

            // now you are able to resize the instance
            $thumbnail->resize($width, $height, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });

            // finally we save the image as a new image
            $thumbnail->save($localFileDestination);
        }

        try {
            if ($this->disk->isExists($destinationPath) === false) {
                // create temp file folder
                $this->disk->createFolder($destinationPath);
            }
        } catch (\Throwable $e) {
            // S3 / object storage does not require folder pre-creation
        }

        if ($this->disk->storeFileAs($destinationPath, $localFileDestination, $fileName)) {
            try {
                $this->disk->setFileAccessType($destinationPath.DIRECTORY_SEPARATOR.$fileName, 'public');
            } catch (\Throwable $e) {
                // S3-compatible providers (Supabase) handle ACL at bucket level
            }

            // Delete file from local server
            if (File::exists($localFileDestination)) {
                File::delete($localFileDestination);
            }

            // return file preview url and file name
            return $this->engineSuccessResponse([
                'path' => getMediaUrl($destinationPath, $fileName),
                'fileName' => $fileName,
                'visibility' => $destinationPath.DIRECTORY_SEPARATOR.$fileName,
                'filePath' => $destinationPath.DIRECTORY_SEPARATOR.$fileName,
                'fileMimeType' => $fileMimeType,
            ], __tr('File Uploaded successfully.'));
        }

        return $this->engineFailedResponse([], __tr('Something went wrong while moving the file.'));
    }

    /**
     * Delete older files
     *
     * @param  string  $dir
     * @param  int  $max_age  - default is 24 hours
     * @return void
     */
    public function deleteOldFiles($dir, $max_age = 3600) // 1 hours
    {
        $list = [];

        $limit = time() - $max_age;

        $dir = realpath($dir);

        if (! is_dir($dir)) {
            return;
        }

        $dh = opendir($dir);
        if ($dh === false) {
            return;
        }

        while (($file = readdir($dh)) !== false) {
            $file = $dir.'/'.$file;
            if (! is_file($file)) {
                continue;
            }

            if (filemtime($file) < $limit) {
                $list[] = $file;
                unlink($file);
            }
        }
        closedir($dh);

        return $list;
    }

    /**
     * Common Process Upload Image
     *
     * @return array
     *---------------------------------------------------------------- */
    public function processUploadedFile($inputFile, $requestFor, $pathValues = [], $options = [])
    {
        $options = array_merge([
            'resize' => null,
        ], $options);
        $uploadedFileOnLocalServer = $this->processUploadFileOnLocalServer($inputFile, $requestFor);
        if ($uploadedFileOnLocalServer['reaction_code'] == 1) {
            $fileName = $uploadedFileOnLocalServer['data']['fileName'];
            $uploadedItemFolderPath = getPathByKey($requestFor, $pathValues);

            $processReaction = $this->resizeImageAndUpload($uploadedItemFolderPath, $fileName);
            if ($processReaction['reaction_code'] == 1) {
                return $this->engineSuccessResponse([
                    'folder_path' => $uploadedItemFolderPath,
                    'file_name' => $fileName,
                    'file_url' => getMediaUrl($uploadedItemFolderPath, $fileName),
                    'file_path' => $uploadedItemFolderPath.DIRECTORY_SEPARATOR.$fileName,
                ], __tr('File Uploaded Successfully'));
            }

            return $this->engineFailedResponse([], __tr('Something went wrong while file moving.'));
        }

        return $uploadedFileOnLocalServer;
    }

    /**
     * Process Translation File Upload
     *
     * @return array
     *---------------------------------------------------------------- */
    public function processUploadTranslationFile($inputFile, $requestFor)
    {
        $logoFolderPath = getPathByKey('language_file');
        $this->disk = YesFileStorage::on('public-media-storage');
        $uploadResult = $this->processUpload($inputFile, $logoFolderPath, $requestFor);
        $this->disk = YesFileStorage::on($this->currentDisk);

        return $uploadResult;
    }
}
