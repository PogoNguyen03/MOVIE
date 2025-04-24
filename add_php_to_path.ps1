# Script để thêm PHP vào biến môi trường PATH
# Chạy script này với quyền quản trị viên (Run as Administrator)

# Đường dẫn đến thư mục PHP
$phpPath = "H:\Work\laragon\bin\php\php-8.1.10-Win32-vs16-x64"

# Kiểm tra xem đường dẫn có tồn tại không
if (-not (Test-Path $phpPath)) {
    Write-Host "Lỗi: Không tìm thấy thư mục PHP tại đường dẫn $phpPath" -ForegroundColor Red
    exit
}

# Lấy giá trị hiện tại của biến PATH
$currentPath = [Environment]::GetEnvironmentVariable("Path", "Machine")

# Kiểm tra xem đường dẫn PHP đã có trong PATH chưa
if ($currentPath -like "*$phpPath*") {
    Write-Host "PHP đã có trong PATH. Không cần thêm." -ForegroundColor Green
} else {
    # Thêm đường dẫn PHP vào PATH
    $newPath = "$currentPath;$phpPath"
    [Environment]::SetEnvironmentVariable("Path", $newPath, "Machine")
    Write-Host "Đã thêm PHP vào PATH thành công!" -ForegroundColor Green
    Write-Host "Bạn cần khởi động lại PowerShell hoặc Command Prompt để các thay đổi có hiệu lực." -ForegroundColor Yellow
}

Write-Host "Nhấn phím bất kỳ để thoát..."
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown") 