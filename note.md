hướng dẫn thao tác PowerShell và cấu hình SePay

Chạy tunnel bằng PowerShell
cloudflared tunnel --url http://localhost:80
Khi thấy dòng Your quick Tunnel has been created!, sao chép địa chỉ HTTPS được in ra, ví dụ https://ten-ngau-nhien.trycloudflare.com.


Để cửa sổ PowerShell đó mở trong suốt lúc thử; đóng cửa sổ hoặc nhấn Ctrl+C sẽ ngắt tunnel. Mỗi lần chạy lại, Cloudflare có thể cấp URL khác.

Cập nhật URL Webhook trong SePay
https://<URL-vừa-nhận>/DoAn2Test/api/payments/bank/sepay

