<?php
// TEMPORARY DEBUG FILE - DELETE AFTER FIXING

echo "<h2>PHP Upload Limits</h2>";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "<br>";
echo "post_max_size: "       . ini_get('post_max_size')       . "<br>";
echo "max_execution_time: "  . ini_get('max_execution_time')  . "<br>";
echo "max_input_time: "      . ini_get('max_input_time')      . "<br>";

echo "<br><h2>Upload Test</h2>";
echo "<form method='POST' enctype='multipart/form-data'>
    <input type='file' name='testfile'>
    <button type='submit'>Upload</button>
</form>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h3>Result:</h3>";
    if (empty($_FILES['testfile'])) {
        echo "❌ \$_FILES is EMPTY — post_max_size is too small, PHP dropped the request entirely<br>";
        echo "POST size received: " . $_SERVER['CONTENT_LENGTH'] . " bytes<br>";
    } else {
        $err = $_FILES['testfile']['error'];
        $errNames = [
            0 => '✅ UPLOAD_ERR_OK — no error',
            1 => '❌ UPLOAD_ERR_INI_SIZE — file exceeds upload_max_filesize in php.ini',
            2 => '❌ UPLOAD_ERR_FORM_SIZE — file exceeds MAX_FILE_SIZE in form',
            3 => '❌ UPLOAD_ERR_PARTIAL — file only partially uploaded',
            4 => '❌ UPLOAD_ERR_NO_FILE — no file uploaded',
            6 => '❌ UPLOAD_ERR_NO_TMP_DIR — no temp folder',
            7 => '❌ UPLOAD_ERR_CANT_WRITE — failed to write to disk',
        ];
        echo ($errNames[$err] ?? "Unknown error code: $err") . "<br>";
        echo "File size: " . $_FILES['testfile']['size'] . " bytes<br>";
    }
}
?>