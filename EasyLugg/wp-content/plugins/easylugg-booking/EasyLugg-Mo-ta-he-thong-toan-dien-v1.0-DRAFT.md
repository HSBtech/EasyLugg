# Mô tả hệ thống toàn diện EasyLugg v1.0 (DRAFT)

|  |  |
|---|---|
| **Phiên bản** | 1.0 — DRAFT |
| **Ngày** | 19/11/2026 |
| **Trạng thái** | DRAFT nội bộ — chờ anh xem và chốt. Chưa gửi team, chưa gửi team dev, chưa lên Drive. |
| **Mục đích** | Một tài liệu duy nhất mô tả đầy đủ EasyLugg: **mô hình kinh doanh, vận hành, phần mềm, working flow** — để bất kỳ ai (team, team dev, người mới) đọc 1 file là hiểu toàn bộ hệ thống. |
| **Cách đọc** | Tài liệu này là **tổng hợp**, không phải bản sao. Chi tiết đầy đủ nằm trong các tài liệu nguồn ở §11 — mỗi mục dưới đây đều ghi rõ nguồn chi tiết. Khi tài liệu này và tài liệu nguồn mâu thuẫn nhau, tài liệu nguồn là chuẩn (báo lại cho Lu để sửa). |
| **Nơi lưu** | Thư mục plugin `easylugg-booking` (bản Phase 1 đang chạy) — vì plugin là hiện thân đang chạy của hệ thống này. Tài liệu mô tả **toàn bộ hệ thống** (gồm cả phần vận hành/admin), không chỉ phần booking web. |

## §1. EasyLugg là gì

**EasyLugg** là dịch vụ giao nhận + lưu trữ hành lý trục **Đà Nẵng – Hội An**, thương hiệu con của **HoiAnGO (HAGO)** ("powered by HAGO" là graphic riêng). Khách hàng ưu tiên giai đoạn đầu: **khách lẻ Hàn Quốc (Korean FIT)** — đi du lịch tự túc, bay đến Đà Nẵng, ở Hội An hoặc ngược lại, cần gửi hành lý để đi chơi tay không trong ngày cuối hoặc giữa các điểm lưu trú.

**Vấn đề giải quyết:** khách có chuyến bay muộn / check-out sớm / muốn đi chơi trước giờ bay nhưng phải mang theo hành lý. EasyLugg nhận hành lý tại khách sạn hoặc sân bay, vận chuyển và giao lại đúng giờ, hoặc giữ tại kho.

**Mốc thời gian:**

| Mốc | Ngày | Trạng thái |
|---|---|---|
| Foundation | 20/10/2026 | Xong |
| Sales Ready | 01/11/2026 | Qua (form demo chạy được) |
| Form Ready | 30/11/2026 | Đang làm — booking form đặt được + checkout (mock) |
| Operation Ready | 01/12/2026 | Sắp tới |
| System Ready | ~15/12/2026 | Theo lịch họp Kien |
| Go Live | 01/01/2027 | Mục tiêu |
| Review | 01/05/2027 | Sau go-live |

**Đội ngũ:**

| Người | Vai trò |
|---|---|
| **Kien Le** | Quy trình dịch vụ + tiêu chuẩn vận hành (SOP), kênh bán, lịch tổng |
| **Miriam** | Website / nội dung / branding, OTA setup |
| **Phuong** | HSB lead; điểm hẹn sân bay |
| **Thanh Nguyen** | Chi phí / dòng tiền tới tháng 6; cùng Kien quyết mức bảo hiểm |
| **Ngoc** | Chi phí vận chuyển, phương tiện, logistics |
| **Le Hien (anh)** | Công nghệ |

## §2. Mô hình kinh doanh

### 2.1. 4 nhóm dịch vụ

| Nhóm | Dịch vụ | Mô tả 1 dòng | Trạng thái |
|---|---|---|---|
| **A** | Storage | Lưu trữ hành lý tại kho Hội An, tính theo kiện/ngày | Đã có trên plugin |
| **B** | Hotel → Hotel | Giao hành lý giữa 2 khách sạn (Đà Nẵng ↔ Hội An) | Đã có trên plugin |
| **C** | Airport ↔ Hotel | Sân bay ↔ khách sạn (2 chiều) | Đã có trên plugin |
| **D** | Door-to-Door / Private (Concierge) | Xe riêng giao tận nơi, theo yêu cầu | **Chưa chốt scope (OD-08)** — chưa có trên plugin |

### 2.2. Kênh bán

| Kênh | Cách vận hành | Trạng thái |
|---|---|---|
| **Website** (easylugg.com) | Khách tự đặt + thanh toán online | Phase 1 — đang xây |
| **OTA** (Klook, Agoda, v.v. — 32 dòng trong danh sách) | Booking qua email → CS nhập tay vào web, sinh mã EasyLugg riêng, **bỏ bước thanh toán** | Nhập tay (không API) |
| **Walk-in** (tại kho/quầy) | Khách tự đặt online tại quầy (web trên điện thoại, nhân viên hỗ trợ) | Theo luồng Storage |
| **Đối tác Hàn Quốc** | Giá riêng theo kênh, không công bố online; đối tác bán trong kênh của họ | Thiết kế xong (§7 tài liệu giá), chưa xây |
| **Kênh CS / điện thoại** | CS tạo booking thay khách | Qua CS |

### 2.3. Mô hình doanh thu

- **Thu theo kiện** (Delivery/Storage: giá mỗi kiện theo size) và **theo dịch vụ** (Private: giá mỗi kiện theo bậc số kiện; phụ phí vị trí theo dịch vụ).
- **Hoa hồng & chi phí** (theo file giá — chưa chốt mức cuối): doanh thu gộp trừ hoa hồng đối tác/marketing, thuế, phí thẻ; OTA lấy 20–30%.
- **Bảo hiểm:** có bảo hiểm cơ bản kèm theo (mức tối đa mỗi kiện **[CHƯA CHỐT — Thanh Nguyen + Kien Le]**; hư hỏng thẩm mỹ — xước, tay cầm, bánh xe — **không bảo hiểm**). Mức 1.500.000 VND/kiện là của **HAGOLug, không phải EasyLugg** — không dùng làm số EasyLugg.
- **Giá khóa tại thời điểm thanh toán (price lock):** đổi config giá sau đó không ảnh hưởng booking đã xác nhận.

### 2.4. Khách hàng & thị trường

- **Ưu tiên:** khách Hàn Quốc đi FIT (đặt lẻ, không qua tour lớn). Kênh liên lạc chính với khách Hàn: **KakaoTalk**. Ngôn ngữ web: **VI / EN / KO**.
- **Thị trường tham chiếu:** đã khảo sát đối thủ (Klook, Bounce, Nanny Bag, GoodLugg, T-Luggage, Bellugg, Yamato, LuggAgent…) — chi tiết trong file giá; giá EasyLugg định vị ở mức cạnh tranh với thị trường Đà Nẵng–Hội An, không chạy đua giá rẻ nhất.

## §3. Sản phẩm & dịch vụ (chi tiết)

### 3.1. Storage (nhóm A)

|  |  |
|---|---|
| **Kho** | Giai đoạn đầu **chỉ 1 kho: HoiAnGO Storage — 97 Nguyễn Tất Thành, Hội An** (V8P9+FR Hoi An Tay, Da Nang, Vietnam) |
| **Giờ kho** | **08:00–20:00**; sau 20:00 tính sang ngày hôm sau; gửi/nhận khác ngày được phép |
| **Giá** | **100.000 VND/kiện/ngày** (flat, không chia size) — giá live trên plugin; **đang chờ tick C-P2** (khối 3 file giá ghi theo size 80/100/180k) |
| **Cách tính ngày** | days = ngày lấy − ngày gửi, tối thiểu 1 ngày; kiện vào kho lúc nào cũng tính 1 ngày |
| **Gia hạn** | Tối đa 30 ngày sau ngày lấy dự kiến, tính thêm phí; quá hạn không lấy → HAGO xử lý theo T&C/pháp luật |
| **Dịch vụ thêm** | Đón tại khách sạn (PU tại Hotel) hoặc tại sân bay (PU tại Airport) thay vì khách tự gửi tại hub — giá = Delivery − Storage (derived, tự cập nhật) |

### 3.2. Delivery (nhóm B + C)

| Tuyến | S/M (120 cm) | L (160 cm) | Oversize (220 cm) |
|---|---|---|---|
| Hotel → Hotel (HOI–HOI) | 130.000 | 150.000 | 230.000 |
| Airport ↔ Hotel (Đà Nẵng) | 130.000 | 150.000 | 230.000 |
| Airport ↔ Hotel (Hội An) | 150.000 | 180.000 | 270.000 (= L × 1,5) |

- **Giá live trên plugin hiện tại: 150.000 / 180.000 / 250.000** (theo size, không chia tuyến) — khác với bảng trên. Bảng trên là theo file giá; **đang chờ tick C-P1** (HOI–DAD có 3 biến thể: 150/180/270 vs 130/150/225 vs live 150/180/250). **Không đổi giá live cho tới khi tick xong.**
- **Size:** Cabin / Medium / Oversize (theo quyết định C1 — khác với MM §9 ghi "Checked"; Oversize tối đa 200 cm theo tick 08/11, khác với 220 cm trong file giá — cần thống nhất).
- **Giới hạn:** tối đa 160 cm (cộng 3 chiều) + 32 kg/kiện (Kien xác nhận 30/10); Oversize tối đa 200 cm.
- **Phụ phí vị trí (tự động):** Sơn Trà 50.000/kiện, Nam Hội An (Bliss, Hoiana, Vinpearl) 50.000/kiện, Bà Nà 80.000/kiện (Delivery); Private 100.000/dịch vụ. Xem tài liệu quy tắc add-on.
- **Qua đêm:** Delivery qua đêm giao trước 17:00 hôm sau; phí lưu qua đêm = (số đêm − 1) × phí lưu × số kiện, đêm đầu miễn phí — **công thức đang chờ tick** (gửi 10/11, chưa trả lời).

### 3.3. Private / Concierge (nhóm D)

- **Xe riêng, freesize (tối đa 220 cm), tối thiểu 2 kiện** (bộ chọn số lượng không xuống dưới 2).
- **Giá theo bậc số kiện** (càng nhiều kiện, giá mỗi kiện càng giảm): từ 2 kiện 230.000/kiện; từ 3–6 kiện 180.000/kiện; từ 7 kiện 120.000/kiện (tuyến Hội An: 270.000 / 230.000 / 120.000).
- **Phụ phí vị trí:** 100.000/dịch vụ (Sơn Trà / Nam Hội An / Bà Nà); Bà Nà thêm 500.000/kiện.
- **Trạng thái:** scope chưa chốt (OD-08) — chưa thiết kế form, chưa có trên plugin. **Markup và giá bậc đang mâu thuẫn trong file giá** (1,5 vs 1,7; bậc 7+ kiện 120k vs 150k) — chờ tick C-P3.

## §4. Vận hành

(Nguồn chi tiết: `EasyLugg-Mo-ta-van-hanh-he-thong.md` — biên bản họp Kien Le 01/11/2026 + Hội đồng phản biện 03/11/2026.)

### 4.1. Các bên tham gia

| Bên | Vai trò |
|---|---|
| Khách | Đặt booking, upload ảnh, để/giao hành lý đúng giờ, nhận hành lý |
| CS (Customer Service) | Tư vấn, xử lý đổi/hủy qua nút một chạm, nhắc khách, xử lý luồng on-request |
| Dispatcher | Điều phối xe 2 vòng/ngày, xử lý delay / no-show / sự cố trong ngày |
| Nhân viên thu gom / giao | Đến điểm hẹn thu/giao, đối chiếu mã + tên, dán tag, scan QR, chụp ảnh, ghi lý do khi lệch |
| Tài xế van | Chạy đúng lịch 2 vòng/ngày |
| Nhân viên kho | Nhận, sắp xếp, trả hành lý tại kho Hội An (08:00–20:00) |
| Ops Manager | Quyết mọi ca lệch/thừa/thiếu kiện, nhận báo Zalo realtime |
| Hệ thống | Sinh mã booking/kiện, tính giá theo config, gửi thông báo, lưu ảnh + log |

### 4.2. Time rules (luật giờ — xương sống của vận hành)

| Luồng | Khách gửi / đón | Nhân viên thu gom | Cam kết giao |
|---|---|---|---|
| Hotel → Airport | Để lễ tân **trước 11:00** | 11:00–14:00 | Theo slot sân bay |
| Airport → Hotel | Slot 08:00–11:00 / 19:00–20:00 (sau hạ cánh ≥ 1h) | Tại điểm hẹn T2 | **Trước 17:00** |
| Hotel → Hotel | Trước 11:00 | 11:00–14:00 | Trước 17:00 (qua đêm: 17:00 hôm sau) |
| Storage | 08:00–20:00 tại kho | — | 08:00–20:00 tại kho |

**Nguyên tắc của Kien:** *khách gửi trước 11:00 → nhân viên đi lấy → giao khách sạn trước 17:00.* Điểm hẹn sân bay duy nhất: **ga quốc tế T2, tầng trệt** (có ảnh chính xác trên web).

### 4.3. Xe và tuyến

| Vòng | Lịch trình |
|---|---|
| **Vòng 1** | Xuất phát Hội An ~07:00 → sân bay thu gom 08:00–11:00 → vòng khách sạn Đà Nẵng (giao + thu) 11:00–14:00 → về kho Hội An ~14:00–16:00 |
| **Vòng 2** | Xuất phát ~16:00 → khách sạn Đà Nẵng → sân bay, chờ đến ~22:00 cho pickup tối |

- Xe van Kim Long, sức chứa **~20 kiện/vòng** (chấp nhận giai đoạn đầu; tính xe thứ hai sau nếu không kịp). Thu gom trong phố Hội An: **xe điện HoiAnGo** khi rảnh 11:00–14/15:00.
- **Chống overbooking:** mỗi slot sân bay và mỗi vòng xe có giới hạn số kiện (config, mặc định theo sức chứa van). Booking vượt công suất → hệ thống **chặn + đề xuất slot khác**. Dispatcher theo dõi board công suất realtime; **ngưỡng cảnh báo 80%**.

### 4.4. Ảnh và bằng chứng bàn giao (POD)

| Thời điểm | Ai chụp | Yêu cầu |
|---|---|---|
| Lúc booking | Khách upload | Bắt buộc khi EasyLugg phải đến khách sạn lấy (bổ sung sau qua tracking page) |
| Check-in (thu gom) | Nhân viên | Tối thiểu **2 ảnh** (trước/sau), nén ~2–3MB |
| Handover (bàn giao) | Nhân viên | Chụp kiện + người nhận / lễ tân |

- **Server-side gate:** thiếu ảnh thì không được hoàn thành check-in.
- Mỗi kiện **1 QR riêng** (`ELUGG-YYMMDD-XXXX-NN`), **nội dung QR có ký số (HMAC)** chống làm giả; tag EasyLugg (in sẵn ~1.000) **dán đè** tag khách sạn lúc thu gom; scan để link kiện → booking.
- Ảnh lưu tối thiểu **365 ngày** → tự động xóa, trừ kiện có claim đang mở (giữ lại đến khi đóng claim).
- Quy tắc đếm: vali chồng lên nhau vẫn tính từng kiện (ghi trong form/T&C).

### 4.5. Liên lạc và nhắc việc

| Kênh | Dùng cho |
|---|---|
| **KakaoTalk** | Kênh chính với khách Hàn (nhắc giờ, xử lý phát sinh) |
| **Email** (bắt buộc lúc booking) | Xác nhận booking, hóa đơn, backup khi không liên lạc được |
| **Zalo** (nội bộ) | Báo ngay cho Ops Manager khi lệch kiện / phát sinh tiền |

**Lịch nhắc:** kiểm booking thiếu thông tin/ảnh **3–5 ngày trước** (tối đa 5–7 ngày) → nhắc **1 ngày trước** → nhắc **2–4h trước** giờ lấy. Số phòng / mã đặt phòng khách sạn **không hỏi lúc booking** (khách chưa biết) — thu qua reminder trước 1 ngày. Không upload ảnh + không liên lạc được mà nhận sai/thiếu → **trách nhiệm của khách**.

### 4.6. Chính sách hành lý

| Nhóm | Ví dụ | Xử lý |
|---|---|---|
| Nguy hiểm | Pin lithium rời, chất cháy nổ, hóa chất | **Từ chối ngay**, ghi log |
| Dễ hỏng | Thực phẩm tươi, hoa quả | Từ chối (không có bảo quản lạnh) |
| Giá trị cao | Tiền mặt, trang sức, giấy tờ gốc | Khuyến cáo khách tự giữ; nếu vẫn gửi → ký **disclaimer miễn trừ** |
| Cồng kềnh đặc biệt | Xe đạp, nhạc cụ lớn | Chỉ nhận khi đã thỏa thuận trước (tính oversize+) |

Nhân viên được quyền từ chối theo bảng này mà **không cần** báo Ops trước (khác với từ chối kiện sai số lượng — vẫn phải báo Ops). Trách nhiệm: EasyLugg **không chịu trách nhiệm** với tiền mặt, trang sức, giấy tờ gốc để trong hành lý; hư hỏng thẩm mỹ **không bảo hiểm**. Quy trình claim: khách báo trong 24h/48h **[CHƯA CHỐT]** kể từ khi nhận → cung cấp ảnh lúc nhận → đối chiếu với 3 ảnh POD → quyết định bồi thường trong [X] ngày làm việc **[CHƯA CHỐT]**.

### 4.7. Xử lý ngoại lệ (tóm tắt — đầy đủ 15 dòng trong file vận hành §11)

| Tình huống | Xử lý |
|---|---|
| Bay delay trong khung giờ | CS chuyển slot tiếp **miễn phí** |
| Delay qua khung giờ | **Tính 50% / hoàn 50%** |
| Bay hạ cánh **sau 22:00** | Giữ hành lý qua đêm hoặc hẹn lại sáng hôm sau; báo khách ngay |
| Khách đổi khách sạn/giờ | Nút CS một chạm (khách **không tự sửa** web); CS sửa đơn, tính lại giá nếu đổi tuyến |
| Khách muốn hủy | Liên hệ CS (không tự hủy web); **miễn phí trước 24h**; hoàn tiền 7–10 ngày |
| Hoàn tiền | CS khởi tạo → Ops Manager duyệt → hoàn đúng kênh đã thu (thẻ: reversal OnePay; tiền mặt: chuyển khoản) |
| Thêm/bớt kiện lúc nhận | Ghi nhận + lý do tay, báo Ops Zalo; thêm kiện: booking mới, tiền mặt + hóa đơn, xong trước giao cuối ở sân bay; giảm kiện không báo trước: **không hoàn tiền** |
| Kiện hỏng/mất | Dừng bàn giao, chụp ảnh, lập biên bản, báo ngay; không tự hứa bồi thường |
| **Mất 1 phần số kiện** | Bàn giao phần còn lại + biên bản; truy vết theo ảnh/scan log; báo Ban quản lý |
| Khách no-show | Giữ kiện tại điểm hẹn, liên hệ KakaoTalk/email, ghi NO_SHOW; phí chờ **[CHƯA CHỐT mức]** |
| Khách tranh chấp "không phải vali của tôi" | Đối chiếu 3 ảnh POD + tag ký số; leo thang lên Ban quản lý |
| Xe hỏng giữa chừng | Điều xe dự phòng ngay, thông báo delay cho khách |
| Khách sạn từ chối giữ hành lý | Chuyển sang điểm hẹn thay thế (kho / điểm hẹn sân bay); báo khách ngay |
| Tag rơi / QR không quét được | Nhập tay mã tag; dán tag dự phòng; log sự cố |
| Thanh toán online lỗi | Đơn ở DRAFT, cho thử lại/đổi phương thức; không tạo booking trùng |

### 4.8. Các quy trình vận hành khác

- **Ủy quyền nhận hộ:** booker đăng ký trước qua CS (tên + SĐT người nhận hộ, trước giờ giao ít nhất [X] giờ — CHƯA CHỐT). Lúc giao đối chiếu giấy tờ tùy thân + mã booking. Không đăng ký trước → **không giao cho người thứ ba** (kể cả có mã booking — mã chụp màn hình forward được, không đủ xác thực).
- **Hành lý vô thừa nhận (Lost & Found):** giữ tại kho, tính phí lưu theo ngày; sau [N] ngày (đề xuất 30 ngày) không liên lạc được → CS gửi thông báo cuối qua mọi kênh; sau đó xử lý theo quy định [CHƯA CHỐT: thanh lý / giao cơ quan chức năng]. Kiện vô thừa nhận được niêm phong, chụp ảnh niêm phong, ghi log.
- **Quản lý tiền mặt:** mọi thu tiền mặt → tạo booking phát sinh + **hóa đơn số liên tục** (không nhảy số). Cuối ca đối chiếu tiền mặt thực tế với tổng booking cash trên hệ thống; chênh lệch → báo Ops Manager ngay trong ca, lập biên bản. Không giữ tiền mặt qua đêm vượt [mức — CHƯA CHỐT].
- **Vận hành khi hệ thống offline:** staff app chạy được khi mất mạng (chụp ảnh/scan lưu local, tự đồng bộ khi có mạng; idempotency chống trùng). Fallback giấy: form check-in giấy; nhập lại khi có mạng; ảnh upload bổ sung trong 24h. Xung đột dữ liệu: server là nguồn duy nhất.
- **Dữ liệu & riêng tư:** PII chỉ role được phân quyền mới xem; **log mọi lượt xem PII**. Ảnh 365 ngày → tự động xóa (trừ claim đang mở).
- **KPI vận hành đề xuất:** giao đúng giờ ≥ 98%; POD đủ ảnh 3 thời điểm 100%; khiếu nại < 5 / 1.000 kiện; xử lý ca lệch kiện < 30 phút; booking thiếu thông tin sau nhắc 2–4h < 3%; chênh lệch tiền mặt cuối ca = 0.

## §5. Phần mềm

(Nguồn chi tiết: `EasyLugg-Thiet-ke-cau-truc-cong-nghe.md` + trạng thái plugin thực tế.)

### 5.1. Kiến trúc tổng thể (mục tiêu)

```
┌──────────────┐      ┌──────────────┐      ┌───────────────────┐
│ Web booking  │─────▶│              │      │  Object storage   │
│ (khách)      │      │   Backend    │─────▶│  (ảnh POD)        │
├──────────────┤      │   API-first  │      ├───────────────────┤
│ Web App      │─────▶│              │─────▶│  Notification     │
│ (nhân viên,  │      │              │      │  KakaoTalk/Email/ │
│  offline-    │      │              │      │  Zalo             │
│  capable)    │      │              │      ├───────────────────┤
├──────────────┤      │              │─────▶│  Payment adapter  │
│ Admin /      │─────▶│              │      │  (OnePay)         │
│ Dispatch     │      └──────┬───────┘      ├───────────────────┤
│ board (CS)   │             │              │  External APIs    │
└──────────────┘             └─────────────▶│  Maps / Flight    │
                                            └───────────────────┘
```

**Nguyên tắc thiết kế (rút từ vận hành):** (P1) mọi tham số chưa chốt phải là **config**, không hard-code; (P2) mọi giao dịch tiền mặt bắt buộc hóa đơn + audit log; (P3) ảnh là bằng chứng — gate ở server, lưu tối thiểu 365 ngày; (P4) tạo booking phải **idempotency** (chống trùng đơn khi thanh toán lỗi); (P5) hệ thống cho phép **cập nhật trạng thái bằng tay** (dự phòng khi automation chưa sẵn); (P6) giá thiếu config → báo lỗi rõ, **không bao giờ** trả giá 0 hay tự sinh giá; (P7) QR/tag phải có ký số (HMAC); (P8) API public phải có rate limiting; (P9) webhook thanh toán phải verify chữ ký + idempotent; (P10) **múi giờ chuẩn duy nhất: Asia/Ho_Chi_Minh**.

**Lưu ý về stack:** thiết kế công nghệ **trung lập** — không bắt buộc WordPress. Demo hiện tại (WordPress + plugin) chứng minh flow chạy được; giữ hay thay bằng stack khác là quyết định của người thiết kế hệ thống.

### 5.2. Phase 1 — những gì đang chạy trên WordPress (thực tế)

Website booking gồm **7 trang**: Home `/`, Delivery `/luggage-delivery/`, Storage `/luggage-storage/`, Private Transfer `/private-transfer/` (chưa thiết kế — chờ OD-08), Book Now `/book-now/`, Track `/track/`, Help `/help/`. Một entry point mỗi dịch vụ, không có đường booking song song.

**Plugin `easylugg-booking` v1.0.1** (repo GitHub private `lehien213/easylugg-booking`, branch `main` — remote head `1a8d47e4` ngày 17/11/2026) là trái tim của Phase 1:

| Hạng mục | Trạng thái hiện tại |
|---|---|
| Booking form | 2 tab Delivery \| Storage (shortcode `[bookingPlugin]` / `[easylugg_booking]`); form **chỉ render tại vị trí đặt shortcode** (auto-append đã bỏ 17/11) |
| Luồng đặt | 3 bước: (1) dịch vụ/tuyến/kiện/ngày giờ, (2) liên hệ + điều khoản, (3) xem lại + thanh toán; Step 2/3 dùng chung cho Delivery + Storage |
| Mã booking | `ELUGG-YYMMDD-XXXX` + mã riêng từng kiện `-NN`; lưu DB riêng (không dùng CPT) |
| Giá | Đọc từ option `easylugg_booking_prices` (wp-admin → EasyLugg Bookings → Luggage Pricing); live: Delivery 150/180/250k, Storage 100k/kiện/ngày; sửa default trong code **không** đổi giá live |
| Storage | 1 kho Hội An; slot 30 phút 08:00–20:00; giá = kiện × ngày × 100k; ngày lấy auto-sync từ ngày gửi; promo code có field, **chưa có logic giảm giá (D11 gap)** |
| Tra cứu booking | Shortcode `[easylugg_tracking]` cho `/track/`: nhập mã → xem toàn bộ booking + timeline trạng thái; rate limit 8 lượt/IP/10 phút; **không hiện passport, không hiện phương thức thanh toán**; link token cũ trong email vẫn hoạt động |
| Trạng thái | `confirmed → collected → in_transit → delivered` (plugin); vòng đời đầy đủ (Pending → … → Completed) là thiết kế Full, chưa có trong plugin |
| Liên hệ | Email bắt buộc + WhatsApp mặc định; tối đa 2 kênh (WhatsApp / Kakao ID / Zalo / Messenger); ≥ 1 kênh bắt buộc |
| Khách sạn | Mock 20 khách sạn (dạng Google Places, có lat/lng); nhập tay ngoài danh sách chưa có ô địa chỉ (đang chờ tick) |
| Ảnh | Khách upload ảnh hành lý (tùy chọn mọi tuyến); HA/HH không ảnh → popup nhắc khi rời Step 1; xem/tải ảnh chỉ dành cho admin |
| i18n | 317 chuỗi trong `texts.php`, domain `easylugg-booking`; `load_textdomain()` đã sửa 17/11 — nhưng **chưa có file .po/.mo**, strings vẫn hiện tiếng Anh cho tới khi dịch trong WPML → String Translation |
| WPML | License đã mua cho site thật; trên localhost phải chọn **'Translate What You Choose'** (thủ công) — **không** bật auto-translate ở local (sẽ ăn quota dịch của site thật); check REST API local lỗi do loopback Docker (bấm "Skip for now") |
| Thanh toán | **Mock only** — chưa có tiền thật. OnePay VN là cổng đã chốt (OD-13); thanh toán thật làm ở Operation Ready |
| Deploy | **Thủ công**: user pull code từ GitHub → build ZIP → upload qua wp-admin (không dùng Git deploy tự động) |

### 5.3. Modules chức năng (M1–M14 — thiết kế Full)

| # | Module | Mô tả | Phase |
|---|---|---|---|
| M1 | Booking Engine | Tạo booking, sinh mã, idempotency-key, state machine; **enforce giới hạn công suất slot** | 11/2026 |
| M2 | Pricing Engine | Tính giá theo config; thiếu config → lỗi; **giá khóa tại thời điểm thanh toán** | 11/2026 |
| M3 | Tracking | Trạng thái booking/kiện (11/2026: hiển thị tĩnh; 12/2026: realtime) | 11–12/2026 |
| M4 | Bag & Tag | QR riêng từng kiện, ký HMAC; scan link kiện → booking; tag dự phòng khi QR hỏng | 12/2026 |
| M5 | Photo | Upload, nén ~2–3MB, **gate thiếu ảnh ở check-in**, lưu 365 ngày → tự động xóa | 12/2026 |
| M6 | Dispatch Board | Gom đơn theo xe/vòng, gán nhân viên, xử lý delay/no-show; board công suất realtime + cảnh báo 80% | 12/2026 |
| M7 | Warehouse | Nhập/xuất kho, tính ngày lưu; quản lý kiện vô thừa nhận | 12/2026 |
| M8 | Payment | Adapter OnePay (thẻ + Apple Pay); tiền mặt/CK + số hóa đơn bắt buộc; **quy trình hoàn tiền** (CS khởi tạo → Ops duyệt) | Mock 11/2026 → thật 12/2026 |
| M9 | Notification | Templates 3 ngôn ngữ (VI/KR/EN); KakaoTalk + email cho khách, Zalo cho Ops; **log che PII** | 12/2026 |
| M10 | Account | Email + password đơn giản, không verification; lịch sử booking (mục đích: marketing DB) | 12/2026 |
| M11 | OTA Import | Nhập tay booking OTA → sinh mã EasyLugg riêng, bỏ bước thanh toán | 12/2026 |
| M12 | Staff Extra Form | Booking phát sinh: tên/tiền/note, tiền mặt, hóa đơn bắt buộc; **đối chiếu tiền mặt cuối ca** | 12/2026 |
| M13 | Reports & Audit | Báo cáo ngày, log tiền mặt, audit trail mọi đổi trạng thái; **audit log lượt xem PII** | 12/2026 |
| M14 | Lost & Found | Theo dõi kiện vô thừa nhận: tính phí lưu/ngày, nhắc hết hạn, log niêm phong/thanh lý | 12/2026 |

### 5.4. Dữ liệu & API (tóm tắt)

**Thực thể chính (19):** `booking` (code, service_type, status, customer, hub, flight_no, total, payment_status, price_locked_at) · `bag` (tag, size, qr_payload ký HMAC, status) · `customer` (name, email + phone bắt buộc, kakaotalk_id) · `proxy_pickup` · `photo` (stage customer/checkin/handover, retained_until) · `hub` · `vehicle` (Kim Long van, ~20 kiện) · `trip_loop` (2 vòng/ngày) · `slot_capacity` · `staff` · `payment` (invoice_no bắt buộc với cash) · `refund` · `price_config` (versioned theo thời gian) · `cash_reconciliation` · `ota_mapping` · `account` · `notification` · `pii_access_log` · `lost_found`.

**API chính (18):** `POST /bookings` (Idempotency-Key + kiểm tra công suất slot) · `GET /bookings/{code}` (tracking) · `PATCH /bookings/{code}` (CS sửa — khách không tự sửa) · `POST /bookings/{code}/cancel` · `POST /bookings/{code}/checkin` (**gate đủ ảnh**) · `POST /bookings/{code}/handover` · `POST /bookings/{code}/proxy-pickup` · `POST /bookings/{code}/bags/{tag}/photos` · `POST /bookings/{code}/scan` (verify HMAC) · `POST /bookings/{code}/claim` · `GET/PUT /admin/pricing` · `GET/PUT /admin/ops-config` · `POST /staff/extra-bookings` · `POST /staff/cash-reconciliation` · `POST /ota/import` · `POST /payments/webhook` (verify signature) · `POST /refunds` · `GET /dispatch/board?date=`.

**Phân quyền (5 vai):** Khách chỉ đặt + xem booking của mình (không tự sửa/hủy — mọi thay đổi qua CS). Nhân viên: check-in/scan/chụp ảnh + tạo booking phát sinh. CS/Dispatcher: sửa/hủy booking, nhập OTA, đăng ký nhận hộ. Ops Manager: duyệt hoàn tiền, duyệt đối chiếu tiền mặt. Admin: config giá/time rules/công suất. Xem PII bị log lại.

**Tích hợp bên ngoài (6):** Google Maps Places Autocomplete (search khách sạn — không xây POI nội bộ) · KakaoTalk (nhắc khách, CS một chạm) · Email SMTP (xác nhận, hóa đơn) · Zalo (báo realtime cho Ops) · OnePay VN (thẻ + Apple Pay, custom API; reversal khi hoàn tiền) · Flight Status API (Phase 2; hiện tại CS tra tay).

### 5.5. Lộ trình xây dựng phần mềm

| Mốc | Phạm vi |
|---|---|
| **30/11/2026** | M1, M2, M3 (tracking tĩnh), M8 (mock payment): booking form đặt được + checkout được |
| **Giữa 12/2026** | M4–M7, M9–M14, M8 (OnePay thật): hệ thống full |
| **~01/01/2027** | Go-live (dự phòng: cập nhật trạng thái bằng tay) |

## §6. Working Flow

(Nguồn chi tiết: bảng tổng hợp 168 yêu cầu — Working Flow, Flows 0–7; `EasyLugg-Booking-Flow-Delivery-v1.0.md` cho Flow 1 chi tiết.)

### Flow 0 — Luồng hệ thống tổng thể (6 bước + Tài chính)

```
BOOKING → CHECK-IN → COLLECTION → STORAGE → CHECK-OUT → INCIDENT
                              ↓
                           FINANCE
```

1. **BOOKING:** khách đặt online / CS nhập tay / OTA nhập tay / walk-in → hệ thống sinh mã `ELUGG-YYMMDD-XXXX` + mã từng kiện `-NN` → tính giá theo config → khóa giá → thanh toán (online) hoặc ghi nhận (tiền mặt/OTA).
2. **CHECK-IN:** nhân viên đối chiếu mã + tên → dán tag EasyLugg → scan QR link kiện → chụp ảnh check-in (tối thiểu 2 ảnh; thiếu ảnh → gate chặn).
3. **COLLECTION:** thu gom tại sân bay T2 / lễ tân khách sạn / kho, theo time rules §4.2; lệch số kiện → ghi lý do tay + báo Ops Zalo ngay.
4. **STORAGE:** kiện vào kho Hội An (08:00–20:00); tính ngày lưu (vào lúc nào cũng 1 ngày); kiện vô thừa nhận → Lost & Found.
5. **CHECK-OUT:** giao tại khách sạn / sân bay / kho; đối chiếu mã + tên (+ giấy tờ nếu nhận hộ); chụp ảnh bàn giao; khách ký nhận.
6. **INCIDENT:** mọi ngoại lệ (§4.7) → biên bản + log + báo cáo; claim → đối chiếu 3 ảnh POD.
7. **FINANCE (xuyên suốt):** thu tiền (online/cash/CK/OTA) → hóa đơn (bắt buộc với cash, số liên tục) → đối chiếu cuối ca → hoàn tiền (CS khởi tạo → Ops duyệt) → báo cáo ngày.

### Flow 1 — Đặt booking online (Delivery, 3 bước)

1. **Bước 1 — Dịch vụ:** chọn tab Delivery → chọn tuyến (AH / HA / HH) → chọn số kiện từng size (Cabin/Medium/Oversize) → chọn ngày dịch vụ + giờ (slot sân bay 08:00–11:00 / 19:00–20:00 cho AH; giờ giao sân bay cho HA) → chọn khách sạn (tìm trong 20 gợi ý hoặc nhập tay) → xem giá live ở Booking Summary.
2. **Bước 2 — Liên hệ:** tên + email (bắt buộc) + kênh liên hệ (WhatsApp mặc định, tối đa 2 kênh) → upload ảnh hành lý (tùy chọn; HA/HH không ảnh → popup nhắc) → đồng ý điều khoản.
3. **Bước 3 — Xem lại & thanh toán:** xem lại toàn bộ (tuyến, ngày giờ, kiện, giá, liên hệ) → chọn phương thức (mock ở Phase 1) → xác nhận → nhận mã booking `ELUGG-YYMMDD-XXXX` + link tracking qua email.
- **Sau booking:** hệ thống nhắc 3–5 ngày trước (kiểm thiếu thông tin/ảnh) → 1 ngày trước → 2–4h trước giờ lấy. Khách **không tự sửa/hủy** trên web — mọi thay đổi qua CS (nút một chạm).

### Flow 2 — Một ngày vận hành mẫu (1 van ~20 kiện, 2 vòng)

| Giờ | Việc |
|---|---|
| 07:00 | Van xuất phát từ Hội An |
| 08:00–11:00 | Thu gom tại sân bay T2 (slot sáng) |
| 11:00–14:00 | Vòng khách sạn Đà Nẵng: giao + thu; xe điện thu gom phố Hội An |
| ~14:00–16:00 | Về kho Hội An (trả kiện lưu / lấy kiện giao chiều) |
| ~16:00 | Vòng 2 xuất phát |
| 16:00–22:00 | Khách sạn Đà Nẵng → sân bay, chờ pickup tối |
| Cuối ca | Đối chiếu tiền mặt (thực tế vs hệ thống) → báo cáo ngày |

Ngày mẫu: tải 10/20 kiện = 50% công suất — còn dư địa cho ngày cao điểm (cảnh báo 80%).

### Flow 3 — Storage check-in / check-out (7 bước mỗi chiều)

**Check-in (gửi):** (1) khách đến kho hoặc chọn đón tại KS/sân bay → (2) đối chiếu mã booking + tên → (3) kiểm số kiện + size → (4) dán tag + scan QR → (5) chụp ảnh check-in → (6) tính ngày lưu (vào lúc nào cũng 1 ngày) → (7) xác nhận, kiện vào vị trí kho.
**Check-out (lấy):** (1) khách đến kho đúng hẹn (hoặc đăng ký nhận hộ trước) → (2) đối chiếu mã + tên (+ giấy tờ nếu nhận hộ) → (3) tính phí lưu theo ngày (gia hạn tính thêm) → (4) thu tiền nếu còn thiếu → (5) lấy kiện khỏi vị trí → (6) chụp ảnh bàn giao → (7) xác nhận hoàn tất.
**Walk-in:** khách đến quầy → tự đặt booking storage online tại chỗ (nhân viên hỗ trợ) → đi theo luồng check-in như trên.

### Flow 4 — Vòng đời tracking

```
Pending / Waiting → Collected / Checked In → In transit → At airport / Delivered → Completed
```

- **Tháng 11 (Phase 1):** hiển thị tĩnh — trạng thái do nhân viên/CS cập nhật tay; khách tra cứu bằng mã booking trên `/track/` (không cần đăng nhập).
- **Tháng 1 (theo MM §2):** trigger thật theo sự kiện (scan/check-in/handover tự đổi trạng thái). Mâu thuẫn lịch: MM §2 nói tháng 1, có nguồn nói giữa tháng 12 — **chưa chốt** (TK-03).
- **Sau Phase 1 (đề xuất):** live tracking/GPS của xe — chưa có trong Phase 1.
- Cách tra cứu (mã booking + gì nữa) là Q1 của Kien — **Miriam quyết**; user đã tự quyết dùng code-lookup ngày 10/11 (đang chạy trên plugin).

### Flow 5 — Đổi / hủy / ngoại lệ

- **Hủy:** miễn phí trước 24h; hoàn tiền 7–10 ngày (CS khởi tạo → Ops duyệt → hoàn đúng kênh đã thu).
- **Đổi:** khách đổi khách sạn/giờ → CS sửa (không tự sửa web); đổi tuyến → tính lại giá.
- **Bay delay:** trong khung giờ → CS chuyển slot tiếp miễn phí; qua khung giờ → tính 50% / hoàn 50%; hạ cánh sau 22:00 → giữ qua đêm hoặc hẹn sáng hôm sau.
- **Lệch kiện:** ghi lý do tay + báo Ops Zalo ngay; thêm kiện → booking mới (tiền mặt + hóa đơn, xong trước giao cuối ở sân bay); giảm kiện không báo trước → **không hoàn tiền**.
- **No-show:** ghi NO_SHOW; phí chờ [CHƯA CHỐT].
- **Kiện hỏng/mất:** dừng bàn giao, chụp ảnh, lập biên bản, báo ngay; không tự hứa bồi thường.

### Flow 6 — OTA & walk-in

**OTA:** booking từ OTA qua email → CS nhập tay vào website → hệ thống sinh mã EasyLugg riêng (mã OTA ≠ mã hãng) → **bỏ bước thanh toán** (khách đã trả trên OTA). Import Excel hàng loạt là phương án làm sau (chưa chốt format). **Không dùng API OTA** (lý do chi phí).
**Walk-in:** xem Flow 3.

### Flow 7 — Door-to-Door / Private

- Tài xế liên hệ khách qua WhatsApp; đến sớm ≥ 60 phút + check-in qua app; chờ ≥ 30 phút.
- Khách no-show → đưa hành lý về kho (thành Storage) thay vì hủy.
- Scope & giá: **TBA** (OD-08 chưa chốt).

## §7. Hệ thống tính giá (tóm tắt)

(Nguồn chi tiết: `EasyLugg-He-thong-tinh-gia-v1.0-DRAFT.md` + `EasyLugg-Quy-tac-tinh-gia-Addon-v1.0-DRAFT.md`.)

**Công thức duy nhất:** `Final Price = (Core Service Price + Add-on Charges) − One Applicable Discount`. Một bill = 1 core service, tối đa 1 discount; Partner Discount không cộng gộp với bất kỳ promo nào khác.

| Thành phần | Quy tắc chính |
|---|---|
| **Core** | Storage: per bag × per day (100k flat). Delivery: per bag × 1 chuyến (giá theo tuyến + size). Private: per bag × 1 chuyến, giá theo bậc số kiện (2 / 3–6 / 7+). |
| **Add-on** | 1 add-on = 5 tham số (Service, Trigger=zone, Basis per bag/per service, Time one-time/per day, Amount Fixed/Derived). Phụ phí vị trí tự động theo khách sạn đã chọn (Sơn Trà/Nam Hội An 50k, Bà Nà 80k Delivery; Private 100k/dịch vụ). Storage PU = Delivery − Storage (derived, tự cập nhật). Việc đón/trả 1 lần thì tính 1 lần (one-time), không tính theo ngày. |
| **Discount** | 4 loại: % (chỉ trên Core) · Fixed (trên toàn bill) · Buy 2 Get 1 (tặng kiện rẻ nhất, admin chọn rẻ/đắt) · Free 1 add-on. Code sai/hết hạn → báo lỗi, yêu cầu gỡ trước khi checkout. |
| **Tiền tệ** | Chỉ tính bằng **VND**. USD/KRW chỉ để hiển thị, lấy tỷ giá live (tính năng riêng, làm sau); không hardcode 25.000. |
| **Kênh** | Mỗi kênh có thể có bảng giá riêng (đối tác Hàn: giá đặc biệt 4 kiện đầu, kiện thêm 100k; mua 4 tặng 1, không công bố) — thiết kế xong, chưa xây. |
| **Admin** | 5 bước cấu hình cho mỗi Booking Form: charging policy → service options → add-on options → package → promo. Giá sống trong DB (rate table), không hardcode trong code. |

## §8. Trạng thái hiện tại (19/11/2026)

### 8.1. Mức độ hoàn thiện theo Requirement Phase 1 v1.2

Trong 137 yêu cầu Phase 1 (bảng tổng hợp 168 dòng): **Đã có trên WordPress 18 · Một phần 23 · Chưa có 96**. Quy tắc Phase 1 (14/11): Phase 1 = những gì đang làm trên WordPress; ngoài WordPress = Full.

**Đã có (18):** giá Storage flat, giá Delivery theo size, form 3 tab (giữ), tra cứu booking, timeline trạng thái, tracking tĩnh, báo cáo ngày (thủ công), và các mục booking-form cơ bản.
**Một phần (23):** 3 tab vs 2 lựa chọn, Google Maps (hiện mock 20 KS), slot tối 19:00–20:00, promo code field (chưa có logic giảm giá), thanh toán (mock), và các mục đang xây dở.
**Chưa có (96) — nhóm quan trọng nhất:** thanh toán thật (OnePay), Google Maps thật, reception/on-request, nút CS một chạm, reminders tự động, tài khoản khách, add-ons tính tiền, go-live gate, và toàn bộ module vận hành (M4–M7, M9–M14).

### 8.2. Những gì đã xong trên máy anh (chờ xác nhận)

- **Plugin:** remote `main` = `1a8d47e4` (17/11, textdomain fix + auto-append removal). Anh cần `git pull` → build ZIP → upload qua wp-admin → kiểm tra: form chỉ hiện ở vị trí shortcode; AH Continue vào được Step 2; `/track/` không còn dòng USD; các yếu tố Stitch v2 (journey card, Stage pill, số thứ tự timeline).
- **OpenCode:** CLI + Desktop đều v2.3.2 (khớp nhau từ 19/11); đúng 11 model bật; default `explabs/deepseek-v4.1-flash:free`, small `explabs/gpt-6-luna:free` (cần đặt trong `opencode.jsonc` rồi kiểm tra `/models`).
- **WPML:** chờ anh bấm "Skip for now" ở màn hình REST API + chọn 'Translate What You Choose' trên local + dịch strings trong String Translation.
- **File `array"` (0 byte, tên lạ) trên GitHub:** chờ anh chạy `git rm` theo lệnh đã gửi 17/11 (push_files không xóa được file).
- **Kien:** file T&C + bảng giá (A2/A3) quá hạn từ 08/11 — **chưa về** (11 ngày quá hạn tính đến 19/11).

### 8.3. Những gì chưa bắt đầu (không tự làm khi chưa có lệnh)

Add-ons tính tiền, logic giảm giá (D11), Private Transfer, tài khoản khách, reminders tự động, Google Maps thật, OnePay thật, Dispatch Board, Staff App, và toàn bộ module M4–M14.

## §9. Quyết định còn mở (tổng hợp — chờ tick)

Tài liệu này **không tự quyết** các mục dưới đây. Mỗi mục đã có phiếu tick riêng trong tài liệu nguồn; bảng này chỉ tổng hợp để anh/Kien nhìn 1 chỗ.

| # | Quyết định | Owner | Nguồn / phiếu |
|---|---|---|---|
| **Giá C-P1..C-P6** | Giá Delivery HOI–DAD; Storage flat hay theo size; Private markup 1,5 vs 1,7 + giá bậc; KRW 18 vs 18.000; GoodLugg 704.000 vs 70.400; Oversize ×1,5 hay cố định | Kien + anh (bảng giá A3 chưa về) | Tài liệu giá §11 |
| **Luật C-P7..C-P14** | Delivery per trip hay per day; phụ phí theo tên KS hay km; tỷ giá live hay cố định; giá theo kênh; OTA tay hay Excel; min 2 kiện; Storage quá 20:00 + gia hạn 30 ngày; làm tròn 1.000 VND | Anh | Tài liệu giá §11 |
| **Add-on D-A1..D-A5** | Quy tắc one-time gắn với C-P7; "Addition 1 piece" 100k là gì; zone KS mới; PU+DO cùng vùng tính 1 hay 2 lần; slot Add-on 1/2 | Anh / Kien | Tài liệu add-on §6 |
| **19 mục ballot** | 13 Chưa chốt + 6 Xung đột trong Requirement Phase 1 (giá GI-01/04/05/09/13, bảo hiểm BH-02/03/05/06/09, nhắc TB-04, thanh toán TT-02/VH-11, NS-05…) | Anh / Kien / Thanh Nguyen / Miriam | Phiếu gửi 17/11, nhắc 18/11 |
| **Ops D1–D7** | Mức bồi thường/kiện; deadline claim 24h/48h + số ngày xử lý; số ngày giữ kiện vô thừa nhận + cách xử lý; điểm tập kết + mức tiền mặt qua đêm; phí no-show; số giờ đăng ký nhận hộ; danh sách vật phẩm cấm | Thanh Nguyen + Kien / Team | File vận hành §20 |
| **Tech D8–D9** | Cách tra cứu tracking (Miriam); RTO/RPO chính thức | Miriam / Team | File công nghệ §10 |
| **OD-02..OD-14** | Bảo hiểm, claim, add-on, giá chính thức, account branding, 16:00 vs 17:00, **Private scope (OD-08)**, Lost & Found, cash max, no-show fee, proxy lead time, OnePay onboarding | Kien / Team | BRD Full v1.1 |
| **SRS v2.1 tick** | Chốt SRS v2.1 (thay v2.0 đã hết hiệu lực) | Anh | Gửi từ 06/11, chưa tick |
| **C7 "chờ pickup"** | Trạng thái chờ pickup sau khi khách upload ảnh (HH) — anh tự nghiên cứu | Anh | Quyết định form C7 |

## §10. Hội đồng phản biện (compact — tự kích hoạt cho tài liệu hệ thống)

- **A (Optimistic/Creative):** Một tài liệu tổng hợp là đúng thứ team cần lúc này — dev mới, Kien, Miriam đều đọc 1 file là hiểu hệ thống, không phải lội 10 tài liệu rời rạc. Đặt nó trong thư mục plugin là hợp lý: code và mô tả đi cùng nhau, ai mở repo cũng thấy.
- **B (Cautious/Risk):** Nguy cơ lớn nhất của tài liệu tổng hợp là **lệch với tài liệu nguồn** theo thời gian (một chỗ đổi giá, 10 chỗ phải đổi theo). Thứ hai: tài liệu này chứa cả phần vận hành/admin trong khi chat này là booking-only — đặt trong plugin có thể gây lẫn ranh giới 2 nền tảng. Thứ ba: file nằm trong repo plugin sẽ bị cuốn vào lần push tiếp theo (manifest tự động) — phải hỏi anh trước khi push.
- **C (Execution/Practical):** Chống lệch bằng cách: tài liệu này chỉ tổng hợp + **ghi rõ nguồn chi tiết ở mỗi mục** (§11) — ai cần chi tiết thì đọc nguồn, không sửa 2 nơi. Ranh giới: ghi rõ trong header là tài liệu mô tả toàn hệ thống, plugin chỉ là nơi lưu. Push: không push tự động — hỏi anh trước.
- **Tổng hợp:** giữ 1 file tổng hợp của A, vá rủi ro của B bằng nguồn trích dẫn + header ghi rõ phạm vi + **không push khi chưa có lệnh**, làm theo pha của C. Tài liệu này là DRAFT — chưa gửi team, chưa lên Drive.

## §11. Tài liệu nguồn (chi tiết nằm ở đây)

| Tài liệu | Nội dung chi tiết | Vị trí |
|---|---|---|
| `BRD-EasyLugg-Sales-MM-v1.1-DRAFT.md` (+ .docx) | BRD từ 5 nguồn, 427 dòng, §1–§15 + xung đột X1–X22 | Goal files + Drive 01-BRD |
| `SRS-EasyLugg-Sales-MM-v1.0-DRAFT.md` | SRS từ 5 nguồn, §8 + xung đột X1–X23 | Goal files (chưa lên Drive) |
| `SRS-EasyLugg-v2.1.md` | SRS theo form v1.1 + quyết định C1–C6, C8 | Drive 01-BRD (chờ tick) |
| `EasyLugg-He-thong-hoan-chinh-Bang-tong-hop-chuc-nang-yeu-cau-v1.0-DRAFT.md` (+ .xlsx) | Bảng tổng hợp 168 yêu cầu + Working Flow (Flows 0–7) | Goal files |
| `EasyLugg-Requirement-Phase1-v1.2-20261114.md` | Requirement Phase 1 (137 dòng, WP 18/23/96) + 96 checkbox kiểm tra | Goal files |
| `EasyLugg-Requirement-Full-v1.0-20261114.md` | Requirement Full (168 dòng) | Goal files |
| `EasyLugg-Mo-ta-van-hanh-he-thong.md` | Mô tả vận hành v2 (20 mục) | Goal files |
| `EasyLugg-Thiet-ke-cau-truc-cong-nghe.md` | Thiết kế công nghệ v2 (modules, entities, APIs) | Goal files |
| `EasyLugg-Booking-Flow-Delivery-v1.0.md` (+ bản EN) | Luồng đặt Delivery 3 bước chi tiết | Goal files |
| `EasyLugg-Storage-Booking-Design-v1.0-DRAFT.md` | Thiết kế Storage booking | Goal files |
| `EasyLugg-Booking-Lookup-Design-v1.0-DRAFT.md` | Thiết kế trang tra cứu /track/ | Goal files |
| `EasyLugg-He-thong-tinh-gia-v1.0-DRAFT.md` | Hệ thống tính giá (công thức, 3 core, promo, kênh, OTA) | Goal files |
| `EasyLugg-Quy-tac-tinh-gia-Addon-v1.0-DRAFT.md` | Quy tắc tính giá add-on (5 tham số, 8 quy tắc) | Goal files |
| `EasyLugg_Pricing Method.xlsx` | File giá gốc (5 sheets) | Drive 01-BRD |
| `EasyLugg-Cau-truc-website-booking.md` | Cấu trúc website 7 trang | Goal files + Drive 02-Spec-va-Flow |
| Plugin `easylugg-booking` (code) | `easylugg-booking.php`, `texts.php`, `includes/storage.php`, `assets/booking*.js`, `assets/*.css` | Thư mục này + GitHub `lehien213/easylugg-booking` |

— Hết Mô tả hệ thống toàn diện EasyLugg v1.0 (DRAFT) —
