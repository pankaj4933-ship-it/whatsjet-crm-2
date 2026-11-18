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
            $fileExtension = strtolower($file->getClientOriginalExtension());
            $fileMimeType = strtolower($file->getClientMimeType() ?: '');
            $fileBaseName = Str::slug(basename($fileOriginalName, '.'.$fileExtension));
            $fileName = uniqid().'---'.$fileBaseName.".$fileExtension";

            // if requested $requestFor not present in $this->elements
            // Then it return invalid request message.
            if (! array_has($this->elements, $requestFor)) {
                return $this->engineFailedResponse([], __tr('Something went wrong.'));
            }

            $restrictions = $this->elements[$requestFor]['restrictions'] ?? [];
            $allowedFileTypes = array_map('strtolower', $restrictions['allowedFileTypes'] ?? []);
            $allowedFileExtensions = array_map('strtolower', $restrictions['allowedFileExtensions'] ?? []);

            // Check restrictions of file
            if (!empty($allowedFileExtensions)) {
                if (! in_array($fileExtension, $allowedFileExtensions)) {
                    return $this->engineFailedResponse(['show_message' => true], __tr('Only __ex__ accepted.', [
                        '__ex__' => implode(', ', $allowedFileExtensions),
                    ]));
                }
            }

            $mimeAliases = [
                'image/jpg' => 'image/jpeg',
                'image/pjpeg' => 'image/jpeg',
                'image/jfif' => 'image/jpeg',
                'image/x-png' => 'image/png',
                'application/ogg' => 'audio/ogg',
                'application/x-ogg' => 'audio/ogg',
                'audio/x-opus+ogg' => 'audio/ogg',
                'audio/opus' => 'audio/ogg',
                'audio/x-m4a' => 'audio/mp4',
                'audio/x-aac' => 'audio/aac',
                'audio/mp3' => 'audio/mpeg',
                'audio/x-mpeg-3' => 'audio/mpeg',
                'audio/mpeg3' => 'audio/mpeg',
                'audio/x-amr' => 'audio/amr',
                'video/x-m4v' => 'video/mp4',
            ];

            $normalizedMimeType = $mimeAliases[$fileMimeType] ?? $fileMimeType;
            $isExtensionAllowed = empty($allowedFileExtensions) || in_array($fileExtension, $allowedFileExtensions);

            $isMimeValid = empty($allowedFileTypes)
                || in_array($fileMimeType, $allowedFileTypes)
                || in_array($normalizedMimeType, $allowedFileTypes)
                || ($isExtensionAllowed && $fileMimeType === 'application/octet-stream')
                || ($isExtensionAllowed && (
                    (str_starts_with($requestFor, 'whatsapp_image') && str_starts_with($fileMimeType, 'image/')) ||
                    (str_starts_with($requestFor, 'whatsapp_audio') && (str_starts_with($fileMimeType, 'audio/') || str_contains($fileMimeType, 'ogg'))) ||
                    (str_starts_with($requestFor, 'whatsapp_video') && str_starts_with($fileMimeType, 'video/')) ||
                    (str_starts_with($requestFor, 'whatsapp_document'))
                ));

            if (! $isMimeValid) {
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
        $extension = strtolower($filesystem->extension($path));
        $originalName = $name.'.'.$extension;
        $mimeType = null;
        try {
            $mimeType = $filesystem->mimeType($path);
        } catch (\Throwable $e) {}

        $knownMimes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'ogg' => 'audio/ogg',
            'oga' => 'audio/ogg',
            'opus' => 'audio/ogg',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'amr' => 'audio/amr',
            'mp4' => 'video/mp4',
            '3gp' => 'video/3gp',
            'pdf' => 'application/pdf',
        ];

        if (!$mimeType || $mimeType === 'application/octet-stream' || ($extension === 'ogg' && str_contains($mimeType, 'ogg')) || (in_array($extension, ['jpg', 'jpeg']) && str_contains($mimeType, 'jpg'))) {
            if (isset($knownMimes[$extension])) {
                $mimeType = $knownMimes[$extension];
            }
        }

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
        if ((! array_has($input, 'filepond') || __isEmpty($input['filepond'])) && !request()->hasFile('filepond')) {
            if (request()->filled('uploaded_media_file_name')) {
                $input['filepond'] = request('uploaded_media_file_name');
            } elseif (request()->filled('filepond')) {
                $input['filepond'] = request('filepond');
            } else {
                return $this->engineFailedResponse(['show_message' => true], __tr('Uploaded file does not exists'));
            }
        }

        $uploadedFile = $input['filepond'] ?? (request()->file('filepond') ?? request('uploaded_media_file_name'));

        if (is_string($uploadedFile)) {
            $uploadedFile = trim($uploadedFile);

            // If JSON string was sent by FilePond or JS client, decode it to get fileName
            if (str_starts_with($uploadedFile, '{') && str_ends_with($uploadedFile, '}')) {
                $decoded = json_decode($uploadedFile, true);
                if (is_array($decoded)) {
                    $uploadedFile = $decoded['fileName'] ?? $decoded['data']['fileName'] ?? $decoded['name'] ?? $uploadedFile;
                }
            }

            // If full URL or relative path was passed, strip down to the basename
            if (str_contains($uploadedFile, '/')) {
                $parsedPath = parse_url($uploadedFile, PHP_URL_PATH);
                $uploadedFile = basename($parsedPath ?: $uploadedFile);
            }

            $userTempPath = getPathByKey('user_temp_uploads', ['{_uid}' => authUID()]);
            $vendorTempPath = getPathByKey('user_temp_uploads', ['{_uid}' => getVendorUid()]);

            // Candidate paths where the temp file could be stored locally
            $candidatePaths = array_unique([
                public_path($userTempPath . DIRECTORY_SEPARATOR . $uploadedFile),
                public_path($vendorTempPath . DIRECTORY_SEPARATOR . $uploadedFile),
                public_path('media-storage/users-temp-uploads' . DIRECTORY_SEPARATOR . $uploadedFile),
                public_path($uploadedFile),
                storage_path('app/' . $userTempPath . DIRECTORY_SEPARATOR . $uploadedFile),
            ]);

            $resolvedLocalPath = null;
            foreach ($candidatePaths as $candidate) {
                if (file_exists($candidate) && is_file($candidate)) {
                    $resolvedLocalPath = $candidate;
                    break;
                }
            }

            // If still not found, search with glob in users-temp-uploads directory
            if (!$resolvedLocalPath) {
                $globMatches = glob(public_path('media-storage/users-temp-uploads/*/*/' . $uploadedFile));
                if (!empty($globMatches) && is_file($globMatches[0])) {
                    $resolvedLocalPath = $globMatches[0];
                }
            }

            // Also search by filename suffix if unique prefix was prepended
            if (!$resolvedLocalPath && str_contains($uploadedFile, '---')) {
                $fileSuffix = explode('---', $uploadedFile, 2)[1] ?? '';
                if ($fileSuffix) {
                    $globMatches = glob(public_path('media-storage/users-temp-uploads/*/*/*---' . $fileSuffix));
                    if (!empty($globMatches) && is_file($globMatches[0])) {
                        $resolvedLocalPath = $globMatches[0];
                    }
                }
            }

            // Also search for recent file in user temp uploads if original name was sent
            if (!$resolvedLocalPath) {
                $cleanName = Str::slug(pathinfo($uploadedFile, PATHINFO_FILENAME));
                if ($cleanName) {
                    $globMatches = glob(public_path('media-storage/users-temp-uploads/*/*/*---*' . $cleanName . '*'));
                    if (!empty($globMatches) && is_file($globMatches[0])) {
                        $resolvedLocalPath = $globMatches[0];
                    }
                }
            }

            // If still not found locally (e.g. Render container restart or multi-instance),
            // restore the temp file from S3 bucket!
            if (!$resolvedLocalPath && $this->currentDisk !== 'public-media-storage') {
                $s3CandidateKeys = array_unique([
                    $userTempPath . '/' . $uploadedFile,
                    $vendorTempPath . '/' . $uploadedFile,
                    'media-storage/users-temp-uploads/' . $uploadedFile,
                ]);

                try {
                    $cloudDisk = YesFileStorage::on($this->currentDisk);
                    foreach ($s3CandidateKeys as $s3Key) {
                        $s3Key = trim($s3Key, '/');
                        if ($cloudDisk->isExists($s3Key)) {
                            $fileContents = $cloudDisk->getFileIfExists($s3Key);
                            if ($fileContents) {
                                $targetLocalDir = public_path($userTempPath);
                                if (!File::isDirectory($targetLocalDir)) {
                                    File::makeDirectory($targetLocalDir, 0777, true, true);
                                }
                                $downloadDest = public_path($userTempPath . DIRECTORY_SEPARATOR . $uploadedFile);
                                file_put_contents($downloadDest, $fileContents);
                                $resolvedLocalPath = $downloadDest;
                                break;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("S3 temp file restore failed: " . $e->getMessage());
                }
            }

            if (! $resolvedLocalPath || ! file_exists($resolvedLocalPath)) {
                return $this->engineFailedResponse(['show_message' => true], __tr('Temporary file expired or not found, please select the image again.'));
            }

            $storagePath = $resolvedLocalPath;
            $uploadedFile = $this->uploadedFileInstance($storagePath);
            $this->disk = YesFileStorage::on($this->currentDisk);
        }

        // Check if file __isEmpty or is valid
        if (__isEmpty($uploadedFile) or ! $uploadedFile->isValid()) {
            return $this->engineFailedResponse(['show_message' => true], __tr('Invalid or Missing uploaded file'));
        }

        $fileOriginalName = $uploadedFile->getClientOriginalName();
        $fileExtension = strtolower($uploadedFile->getClientOriginalExtension());
        $fileMimeType = strtolower($uploadedFile->getClientMimeType() ?: '');
        $fileBaseName = str_slug(basename($fileOriginalName, '.'.$fileExtension));
        $fileName = $fileBaseName.'-'.uniqid().'.'.$fileExtension;

        $restrictions = $this->elements[$allowedExtension]['restrictions'] ?? [];
        $allowedFileTypes = array_map('strtolower', $restrictions['allowedFileTypes'] ?? []);
        $allowedFileExtensions = array_map('strtolower', $restrictions['allowedFileExtensions'] ?? []);

        // Check restrictions of file extension
        if (!empty($allowedFileExtensions)) {
            if (!in_array($fileExtension, $allowedFileExtensions)) {
                return $this->engineFailedResponse(['show_message' => true], __tr('Only __ex__ accepted.', [
                    '__ex__' => implode(', ', $allowedFileExtensions),
                ]));
            }
        }

        $mimeAliases = [
            'image/jpg' => 'image/jpeg',
            'image/pjpeg' => 'image/jpeg',
            'image/jfif' => 'image/jpeg',
            'image/x-png' => 'image/png',
            'application/ogg' => 'audio/ogg',
            'application/x-ogg' => 'audio/ogg',
            'audio/x-opus+ogg' => 'audio/ogg',
            'audio/opus' => 'audio/ogg',
            'audio/x-m4a' => 'audio/mp4',
            'audio/x-aac' => 'audio/aac',
            'audio/mp3' => 'audio/mpeg',
            'audio/x-mpeg-3' => 'audio/mpeg',
            'audio/mpeg3' => 'audio/mpeg',
            'audio/x-amr' => 'audio/amr',
            'video/x-m4v' => 'video/mp4',
        ];

        $normalizedMimeType = $mimeAliases[$fileMimeType] ?? $fileMimeType;
        $isExtensionAllowed = empty($allowedFileExtensions) || in_array($fileExtension, $allowedFileExtensions);

        $isMimeValid = empty($allowedFileTypes)
            || in_array($fileMimeType, $allowedFileTypes)
            || in_array($normalizedMimeType, $allowedFileTypes)
            || ($isExtensionAllowed && $fileMimeType === 'application/octet-stream')
            || ($isExtensionAllowed && (
                (str_starts_with($allowedExtension, 'whatsapp_image') && str_starts_with($fileMimeType, 'image/')) ||
                (str_starts_with($allowedExtension, 'whatsapp_audio') && (str_starts_with($fileMimeType, 'audio/') || str_contains($fileMimeType, 'ogg'))) ||
                (str_starts_with($allowedExtension, 'whatsapp_video') && str_starts_with($fileMimeType, 'video/')) ||
                (str_starts_with($allowedExtension, 'whatsapp_document'))
            ));

        if (!$isMimeValid) {
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
            if (isset($resolvedLocalPath) && file_exists($resolvedLocalPath)) {
                $moved = File::copy($resolvedLocalPath, $targetFilePath);
                if ($moved && $resolvedLocalPath !== $targetFilePath) {
                    @File::delete($resolvedLocalPath);
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
        if (!File::exists($localFileDestination)) {
            $vendorPath = getPathByKey('user_temp_uploads', ['{_uid}' => getVendorUid()]);
            $candidates = [
                public_path($vendorPath . '/' . $fileName),
                public_path('media-storage/users-temp-uploads/' . $fileName),
                $path . '/' . $fileName,
            ];
            foreach ($candidates as $cand) {
                if (File::exists($cand)) {
                    $localFileDestination = $cand;
                    break;
                }
            }
            if (!File::exists($localFileDestination)) {
                $matches = glob(public_path('media-storage/users-temp-uploads/*/*/' . $fileName));
                if (!empty($matches) && File::exists($matches[0])) {
                    $localFileDestination = $matches[0];
                }
            }
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
            // Keep a local copy in public media storage so Chrome and local players can always stream it
            $localFinalPath = public_path($destinationPath . DIRECTORY_SEPARATOR . $fileName);
            $localFinalDir = dirname($localFinalPath);
            if (!File::isDirectory($localFinalDir)) {
                File::makeDirectory($localFinalDir, 0777, true, true);
            }
            if (File::exists($localFileDestination) && $localFileDestination !== $localFinalPath) {
                File::copy($localFileDestination, $localFinalPath);
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
