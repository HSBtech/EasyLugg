# SRS — EasyLugg v1.0

| Thuộc tính | Giá trị |
| --- | --- |
| Phiên bản | v1.0 — DRAFT phê duyệt |
| Ngày | 19/11/2026 |
| Trạng thái | Chưa triển khai; là chuẩn đầu vào cho development |
| Sản phẩm | WordPress plugin `easylugg-booking` |
| Ngôn ngữ tài liệu | Tiếng Việt; mã nguồn, API field và comment dùng tiếng Anh |

## 1. Mục đích và nguồn chuẩn

Tài liệu này đặc tả yêu cầu phần mềm cho EasyLugg: booking/tracking của khách và Operations App cho nhân sự. Mục tiêu là để team dev có thể triển khai mà không tự suy diễn quy trình vận hành.

Thứ tự ưu tiên khi có mâu thuẫn:

1. Quyết định được owner phê duyệt sau ngày tài liệu này.
2. `EasyLugg-Booking-Flow-Delivery-v1.0-en.md` cho Delivery booking flow.
3. `EasyLugg-Mo-ta-he-thong-toan-dien-v1.0-DRAFT.md` cho vận hành và mục tiêu hệ thống.
4. SRS này.
5. Hành vi plugin đang chạy.

Giá, giới hạn xe, chính sách hoàn tiền, no-show, nhận hộ, các tích hợp bên thứ ba và các mục ghi `PENDING` không được tự hard-code hoặc giả định là đã được phê duyệt.

## 2. Phạm vi

### 2.1 Bao gồm

- Customer web: Delivery (AH/HA/HH), Storage, estimate, booking, mock payment, confirmation và tracking.
- Operations App cho toàn bộ nhân sự: đăng nhập, dashboard, điều phối và xử lý booking/bag tại hiện trường.
- CRUD booking nội bộ: Delivery, Storage, OTA/manual và booking phát sinh tại hiện trường.
- Dispatch theo ngày, xe/vòng, capacity, nhân sự và ngoại lệ.
- Tag/QR, check-in, ảnh POD, handover, incident, cash/invoice, refund approval, notification log, report/audit.
- Vai trò, dữ liệu, API, migration và yêu cầu an toàn dữ liệu.

### 2.2 Không bao gồm ở lần triển khai đầu

- Private/Concierge service cho tới khi phạm vi và bảng giá được phê duyệt.
- Tối ưu tuyến bản đồ tự động, tracking GPS trực tiếp, flight-status API và offline sync hoàn chỉnh. Kiến trúc phải cho phép bổ sung nhưng không giả lập chúng là đã có.
- Thanh toán OnePay thật, KakaoTalk, Zalo, Google Places thật trước khi từng adapter có credential và contract được phê duyệt.

## 3. Kiến trúc và entry point

### 3.1 Kiến trúc mục tiêu

Plugin là nguồn dữ liệu duy nhất. Tách code theo các module: `Domain` (booking/bag/dispatch rules), `Repository` (wpdb), `Rest` (controller/permission), `Ops` (render và asset), `Customer` (form/tracking), `Integrations` và `Audit`. Không tiếp tục thêm nghiệp vụ Ops lớn vào `easylugg-booking.php`.

Frontend Ops là web app responsive, ưu tiên điện thoại cho Staff và tablet/desktop cho CS/Dispatcher. App gọi REST `easylugg/v1`; mọi validation và authorization được thực thi lại ở server. Customer flow hiện có có thể giữ AJAX trong giai đoạn chuyển tiếp, nhưng dữ liệu phải dùng chung domain model.

### 3.2 Shortcode

Chỉ có một entry point cho Ops App:

```text
[easylugg_ops]
```

- Khách chưa có session Ops thấy màn **Login**.
- Session hợp lệ mở route **Dashboard** mặc định.
- Route **Coordinate** là workspace vận hành: booking CRUD, dispatch, bag và POD. Đây không phải trang bản đồ riêng.
- User không có quyền bị trả về màn lỗi 403 trong app; URL/REST request không được dùng để vượt quyền.
- Shortcode customer hiện hữu (`[bookingPlugin]`, `[easylugg_booking]`, `[easylugg_tracking]`) tiếp tục độc lập và không cần session Ops.

## 4. Người dùng và phân quyền

### 4.1 Tài khoản nhân sự

Tài khoản nhân sự là thực thể riêng của plugin, không phải WordPress user. Admin tạo tài khoản, đặt mật khẩu ban đầu, đổi role, khóa/mở khóa và đặt lại mật khẩu trong Operations App. Không có self-registration. V1 không yêu cầu email reset password; Admin thực hiện reset và sự kiện phải được audit.

Mật khẩu phải được hash bằng API password của WordPress; không lưu plaintext, token phiên plaintext hay reset token plaintext. Session có cookie `HttpOnly`, `Secure` khi site HTTPS, `SameSite=Lax`, expiration server-side, revoke khi account bị khóa hoặc đổi mật khẩu. Đăng nhập thất bại bị rate limit theo account và IP; thông báo không tiết lộ account có tồn tại hay không.

### 4.2 Role matrix

| Khả năng | Staff | CS / Dispatcher | Ops Manager | Admin |
| --- | :---: | :---: | :---: | :---: |
| Xem worklist được gán và PII tối thiểu | ✓ | ✓ | ✓ | ✓ |
| Tạo booking phát sinh | ✓ | ✓ | ✓ | ✓ |
| Check-in, tag/scan, POD, handover | ✓ | ✓ | ✓ | ✓ |
| Xem/sửa booking nội bộ | — | ✓ | ✓ | ✓ |
| Tạo Delivery/Storage/OTA booking nội bộ | — | ✓ | ✓ | ✓ |
| Hủy booking / tạo yêu cầu refund | — | ✓ | ✓ | ✓ |
| Gán xe/vòng/nhân viên, xử lý no-show/incident | — | ✓ | ✓ | ✓ |
| Duyệt refund và cash reconciliation | — | — | ✓ | ✓ |
| Giá, time rule, capacity, vehicle, integration config | — | — | — | ✓ |
| Staff account / role / khóa account | — | — | — | ✓ |
| Xem audit log đầy đủ | — | — | ✓ | ✓ |

Customer chỉ tạo booking và xem booking của chính mình qua tracking token; không được tự sửa hoặc hủy. Mỗi lần xem PII, tải ảnh riêng tư, đổi trạng thái, thay đổi tiền, gán dispatch và quản trị account phải tạo audit event.

## 5. Mô hình trạng thái

### 5.1 Booking

| State | Ý nghĩa | Chuyển tiếp hợp lệ |
| --- | --- | --- |
| `draft` | Đơn nội bộ hoặc payment chưa hoàn tất | `requested`, `confirmed`, `cancelled` |
| `requested` | Cần CS xác minh/on-request | `confirmed`, `cancelled` |
| `confirmed` | Đủ điều kiện vận hành | `scheduled`, `cancelled` |
| `scheduled` | Đã vào xe/vòng hoặc worklist | `checked_in`, `cancelled`, `no_show` |
| `checked_in` | Đã đối chiếu và nhận hành lý | `collected` |
| `collected` | Hành lý đã thu gom | `in_transit` |
| `in_transit` | Đang vận chuyển | `ready_for_handover`, `delivered` |
| `ready_for_handover` | Đã tới điểm giao/chờ xác nhận | `delivered`, `incident` |
| `delivered` | Đã bàn giao thành công | — |
| `no_show` | Khách không xuất hiện | `scheduled`, `incident`, `cancelled` |
| `incident` | Có sự cố cần Ops xử lý | trạng thái đích do Ops Manager chọn và ghi lý do |
| `cancelled` | Hủy | — |

Chuyển trạng thái luôn đi qua service server-side, yêu cầu role phù hợp, reason nếu là hủy/no-show/incident hoặc bỏ qua bước, và ghi `booking_event`. Không cập nhật trực tiếp cột status từ client.

### 5.2 Bag

Mỗi bag có tag duy nhất và trạng thái `expected → checked_in → collected → in_transit → handed_over`; bag có thể là `exception` hoặc `lost_found`. Không được chuyển booking sang `checked_in` khi chưa có ít nhất số bag thực nhận và ảnh check-in tối thiểu theo policy. Số ảnh chính thức, retention và quy trình claim phải lấy từ ops configuration đã được phê duyệt.

## 6. Yêu cầu chức năng

### FR-01 — Customer booking và tracking

1. Delivery phải hỗ trợ AH, HA, HH theo flow Delivery v1.0; Storage giữ luồng hiện có.
2. Server là nguồn chuẩn cho validation, price calculation, request idempotency, photo access và booking code.
3. Customer không tự sửa/hủy; UI chỉ có kênh liên hệ CS.
4. Tracking chỉ trả dữ liệu đã được sanitize cho customer; không trả passport, payment method, raw coordinates, staff note, audit log hoặc private filesystem path.
5. Online payment chưa được mô tả là payment thật cho đến khi OnePay adapter được phê duyệt; thất bại phải giữ `draft` và không tạo booking trùng.

### FR-02 — Ops authentication và account management

1. Login nhận identifier và password, tạo session mới khi thành công, audit `login_success`; lỗi audit `login_failed` không chứa password.
2. Logout revoke session hiện tại. API `me` trả account, role và capability đã resolve.
3. Admin có staff list với filter active/locked/role; có thể create, update display name/role, lock/unlock và set password.
4. Không cho phép Admin tự hạ role/khóa tài khoản Admin cuối cùng còn active. Server phải từ chối thay đổi làm mất toàn bộ Admin active.

### FR-03 — Dashboard

Dashboard mặc định là ngày hiện tại theo timezone WordPress và cho phép đổi ngày. Màn hình hiển thị:

- Tổng booking và tổng bag theo state; Delivery và Storage tách riêng.
- Worklist đến hạn, chưa gán, thiếu ảnh/thông tin, no-show/incident và đơn chờ duyệt.
- Capacity từng vòng xe theo số bag đã schedule so với capacity cấu hình; cảnh báo khi đạt ngưỡng configuration.
- Tóm tắt cash/reconciliation và refund pending cho role được phép.
- Chỉ số/công việc bấm được để mở Coordinate với filter tương ứng.

Staff chỉ thấy worklist được gán và chỉ số không lộ PII/tài chính không cần thiết. Không hiển thị số liệu giả khi dữ liệu chưa được cấu hình.

### FR-04 — Coordinate: booking workspace

1. Hiển thị danh sách có server-side pagination, tìm theo booking code/tag/customer name/flight, filter date/service/corridor/status/payment/assignment/exception và sort an toàn bằng field whitelist.
2. Mở booking phải hiển thị timeline, booking details, bag list, dispatch assignment, payment summary, photos theo quyền và audit summary.
3. CS/Dispatcher trở lên tạo nội bộ Delivery, Storage, OTA/manual. Form phải dùng cùng validation domain với customer booking; OTA/manual được đánh dấu source, payment handling và note nội bộ rõ ràng.
4. Sửa booking chỉ cho field còn hợp lệ với trạng thái hiện tại. Mọi thay đổi phải có reason, recalculation/price-lock policy rõ ràng và notification request nếu có thay đổi ảnh hưởng khách.
5. Hủy chỉ CS/Dispatcher trở lên, yêu cầu reason; refund là workflow riêng, không tự đổi payment state thành refunded.
6. Staff tạo booking phát sinh chỉ gồm thông tin tối thiểu được phê duyệt, số tiền, lý do, payment cash/bank theo policy và invoice bắt buộc khi tiền mặt.

### FR-05 — Dispatch và capacity

1. Dispatcher tạo/cập nhật vehicle, trip loop và assignment theo service date. Một booking có thể được gán pickup/delivery leg khi quy trình cần tách chặng.
2. Hệ thống tính capacity bằng tổng bag active trên leg/vòng, không bằng số booking. Vượt capacity bị chặn server-side; ngưỡng cảnh báo và capacity là config Admin, không hard-code từ giá trị xấp xỉ trong tài liệu vận hành.
3. Dispatcher gán/reassign Staff; Staff chỉ thấy task được gán trừ khi role được mở rộng sau này.
4. Delay, vehicle issue, hotel refusal, no-show và bag mismatch tạo incident có type, detail, owner, created/closed timestamp và escalation flag. Notification adapter chỉ chạy khi cấu hình/contract đã có.

### FR-06 — Check-in, tag/QR, POD và handover

1. Staff tìm booking bằng booking code hoặc quét tag. QR payload phải xác thực server-side trước khi link bag; không tin tag ID do client tự gửi.
2. Check-in ghi actual bag count/size, discrepancy reason, tag assignment, ảnh POD và staff/time/location context. Nếu lệch số lượng, tạo incident và chặn hoặc yêu cầu escalation theo policy.
3. Bước check-in bị chặn khi thiếu ảnh tối thiểu theo policy. Đã qua gate mới cho booking/bag đi tiếp `checked_in`/`collected`.
4. Handover yêu cầu booking/bag, loại receiver (customer/reception/proxy), receiver name và ảnh POD theo policy. Proxy chỉ được chấp nhận khi registration trước đó hợp lệ.
5. Ảnh là private object. Client chỉ nhận signed/authorized download route, không public upload path.

### FR-07 — Finance, notification, reporting

1. Payment record tách khỏi booking status: method, amount, state, provider reference, invoice reference và audit. OnePay webhook chỉ được mở khi có signature contract.
2. Cash payment/booking phát sinh phải có invoice number liên tục; không được dùng `MAX()+1` không transaction-safe.
3. CS tạo refund request; Ops Manager duyệt/từ chối với reason. Provider reversal là adapter riêng và không được giả lập là đã thành công.
4. Notification được tạo từ domain event, có template/language/channel/status/retry log; log không chứa secret và che PII không cần thiết.
5. Báo cáo ngày gồm bookings, bags, capacity, delivery performance, incidents, cash/reconciliation và refunds theo quyền xem.

## 7. Dữ liệu mục tiêu

Các bảng dùng prefix WordPress và foreign key logic được bảo vệ ở application layer nếu môi trường không bảo đảm FK:

| Nhóm | Bảng / dữ liệu chính |
| --- | --- |
| Identity | `easylugg_ops_accounts`, `easylugg_ops_sessions` |
| Booking | `easylugg_bookings`, `easylugg_booking_events`, `easylugg_booking_contacts`, `easylugg_booking_assignments` |
| Bag/POD | `easylugg_bags`, `easylugg_bag_scans`, `easylugg_photos` |
| Dispatch | `easylugg_vehicles`, `easylugg_trip_loops`, `easylugg_dispatch_legs`, `easylugg_incidents` |
| Finance | `easylugg_payments`, `easylugg_invoices`, `easylugg_refunds`, `easylugg_cash_reconciliations` |
| Support | `easylugg_notifications`, `easylugg_audit_logs`, versioned operational config |

`easylugg_bookings` hiện hữu là dữ liệu legacy cần giữ. Migration thêm ID nội bộ bất biến nếu cần, không thay booking code, không xóa `booking_data`, giữ tracking-token hash, photo path và trạng thái legacy. Mapping trạng thái legacy phải ghi rõ và chạy idempotent; backup/database export trước migration là bắt buộc vận hành.

Mọi thời gian lưu UTC và render theo timezone WordPress; service date là local date. Monetary values lưu integer VND; giá lock tại thời điểm confirmed/payment theo policy phê duyệt, không đọc giá hiện tại để sửa lịch sử.

## 8. REST API contract

Tất cả endpoint dưới `/wp-json/easylugg/v1`. Response lỗi có `{ "code", "message", "details", "request_id" }`; không trả stack trace, SQL, path hoặc secret. Endpoint mutation yêu cầu `Idempotency-Key` khi request có thể retry bởi mạng/app.

| Nhóm | Endpoint | Quyền | Hành vi |
| --- | --- | --- | --- |
| Auth | `POST /ops/auth/login`, `POST /ops/auth/logout`, `GET /ops/me` | public/session | Tạo/revoke/đọc session Ops |
| Accounts | `GET/POST /ops/accounts`, `GET/PATCH /ops/accounts/{id}`, `POST /ops/accounts/{id}/reset-password` | Admin | Quản trị staff account |
| Dashboard | `GET /ops/dashboard?date=` | session | Kết quả theo capability và date |
| Bookings | `GET/POST /ops/bookings`, `GET/PATCH /ops/bookings/{code}`, `POST /ops/bookings/{code}/cancel` | role matrix | List/detail/create/update/cancel |
| Events | `GET /ops/bookings/{code}/events` | scoped PII | Timeline và audit booking |
| Bags | `POST /ops/bookings/{code}/check-in`, `POST /ops/bookings/{code}/bags`, `POST /ops/bags/scan`, `POST /ops/bookings/{code}/handover` | Staff+ | Operations có gate POD |
| Photos | `POST /ops/photos`, `GET /ops/photos/{id}` | scoped | Upload metadata/object và download authorized |
| Dispatch | `GET/POST /ops/dispatch/loops`, `PATCH /ops/dispatch/loops/{id}`, `POST /ops/dispatch/assignments` | CS/Dispatcher+ | Loop, capacity, assignment |
| Finance | `POST /ops/refunds`, `POST /ops/refunds/{id}/decision`, `POST /ops/cash-reconciliations` | matrix | Request/approve refund và chốt ca |
| Config | `GET/PATCH /ops/config`, vehicle endpoints | Admin | Policy/price/capacity/integration config |
| Audit/report | `GET /ops/audit`, `GET /ops/reports/daily` | Ops Manager+ | Dữ liệu theo phạm vi quyền |

`POST /ops/bookings/{code}/check-in` phải nhận `expected_version` hoặc equivalent optimistic concurrency token. Khi phiên bản dữ liệu đã đổi, trả `409 conflict` kèm dữ liệu cần reload; không ghi đè silent. Upload ảnh phải tách request khỏi transition: API transition nhận danh sách photo ID đã authorize và kiểm tra đủ policy trong cùng transaction logic.

## 9. Quy tắc validation quan trọng

- Delivery AH/HA/HH, Storage, bag count, date/time, hotel, contact và photo dùng các rule từ booking flow; server luôn là authoritative.
- `AH`: chỉ cho slot hợp lệ và thời điểm theo landing rule; `HA`: airport delivery không muộn hơn departure trừ 4 giờ; `HA/HH` áp dụng photo requirement tại booking theo flow đã phê duyệt.
- Bag size, tag code, status key, sort/filter key và role đều là enum server-side whitelist.
- Bất kỳ thay đổi booking khi đã schedule/check-in phải kiểm tra dispatch/capacity và cảnh báo impacted parties; không cập nhật tọa độ/giá/số tiền âm thầm.
- Tất cả request mutation có CSRF/session protection, input sanitization theo field type, nonce/anti-replay phù hợp REST session và authorization ở object level.

## 10. Yêu cầu phi chức năng

| ID | Yêu cầu |
| --- | --- |
| NFR-01 | PHP 7.4 và WordPress 6.0+; không phụ thuộc build step bắt buộc để render chức năng cốt lõi. |
| NFR-02 | Ops UI responsive; tác vụ scan/check-in/handover thực hiện được trên màn hình mobile. |
| NFR-03 | Danh sách booking phân trang server-side; query có index theo code, date, status, assignment để không tải toàn bảng. |
| NFR-04 | PII, ảnh và token không xuất hiện trong log browser, URL query, analytics hoặc error response. |
| NFR-05 | Audit log append-only đối với actor, action, object, before/after redacted, timestamp và request ID. |
| NFR-06 | Private photo có policy retention; job xóa phải bỏ qua claim/hold đang mở. Số ngày retention là `PENDING`. |
| NFR-07 | Offline-first là target sau; v1 trả lỗi rõ ràng khi mất mạng, không tuyên bố đã offline sync. |
| NFR-08 | Mọi schema change tăng DB version, dùng migration idempotent và có rollback/restore runbook. |

## 11. Kế hoạch triển khai theo dependency

1. **Foundation:** refactor module boundary, migration framework, audit, configuration, account/session/role, REST base và `[easylugg_ops]` login.
2. **Ops booking:** dashboard, searchable Coordinate, booking detail, nội bộ CRUD, event timeline và object-level authorization.
3. **Field operations:** bags/tags/QR, private photos, check-in/handover gates, incidents và worklists.
4. **Dispatch:** vehicles, loops, assignment, capacity enforcement và exception dashboard.
5. **Finance/integration:** invoices, reconciliation, refund workflow, notification adapter và approved external providers.
6. **Hardening:** reporting, retention jobs, performance, security review, migration rehearsal và user acceptance test.

Mỗi phase chỉ được release sau khi migration, permission tests, PHP syntax checks, REST authorization tests và luồng UI thực tế trên localhost đều pass.

## 12. Acceptance scenarios

1. Anonymous mở `[easylugg_ops]` chỉ thấy Login; password sai bị rate-limit và không lộ identifier tồn tại.
2. Admin tạo Staff, Staff login, chỉ xem worklist được gán; Staff không gọi được account/config/refund endpoint.
3. CS tạo Delivery/Storage/OTA booking; validation và price source theo config, request retry không tạo trùng.
4. Dispatcher gán booking vào loop; capacity vượt ngưỡng cảnh báo và vượt maximum bị server chặn.
5. Staff check-in một booking: scan tag hợp lệ, ghi bag thực tế, upload đủ ảnh, trạng thái chuyển hợp lệ và audit có actor. Thiếu ảnh bị chặn.
6. Handover lưu receiver/POD; proxy không có registration bị từ chối.
7. Bag count mismatch/no-show/vehicle delay tạo incident và chỉ role đúng mới đóng/escalate.
8. Cash extra booking có invoice; refund chỉ chuyển tiếp sau Ops Manager duyệt.
9. Customer tracking không trả PII nội bộ, payment method, raw coordinate, staff note hoặc photo path.
10. Migration trên bản copy DB giữ nguyên booking code/tracking link legacy và có thể chạy lại không nhân bản dữ liệu.

## 13. Decision register

| ID | Quyết định còn thiếu | Owner đề xuất | Ảnh hưởng |
| --- | --- | --- | --- |
| D-01 | Giá Delivery theo tuyến/size và add-on chính thức | Kien + Finance | pricing, price lock, invoice |
| D-02 | Capacity/alert threshold chính thức theo xe/vòng | Ops | dispatch validation |
| D-03 | OnePay/Apple Pay/Google Pay contract và webhook | Finance + Tech | payment adapter |
| D-04 | Proxy-pickup cutoff và evidence | Ops | handover validation |
| D-05 | No-show fee, policy hoàn tiền và approval limit | Finance + Ops | cash/refund workflow |
| D-06 | Photo retention, claim hold và storage provider | Ops + Legal | lifecycle job |
| D-07 | Google Maps, KakaoTalk, Zalo, SMTP provider contracts | Owner + Tech | integration adapters |
| D-08 | Private/Concierge service scope | Business owner | new service workflow |

---

*Tài liệu DRAFT: mọi mục `PENDING` cần được phê duyệt trước khi code nghiệp vụ tương ứng được triển khai.*
