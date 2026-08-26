# ADMAS University — Nidaamka Xaadirinta (Attendance System)

Nidaamkani waa **web application** loogu talagalay **ADMAS University** (Garoowe Campus, Puntland, Somalia), oo maamula xaadirinta ardayda, koorsooyinka, macalimiinta, iyo maamulka guud ee tacliinta — isagoo ku dhisan **PHP + MySQL + Bootstrap 5**.

Aqrintani waxay kuu sheegaysaa **sida user-ku (isticmaale kastaba) u isticmaalo nidaamka**, laga bilaabo gelitaanka (login) ilaa dhammaadka — iyo sida boggagu (sidebar items) ay **isu kala horreeyaan**, si aad u fahamto habka loo maro marka la bilaabo isticmaalka nidaamka.

---

## 1. Sida loo Galo Nidaamka (Login)

- Bogga hore ee la arko waa **`login.php`**.
- Waxaad geliysaa: **Username/Email**, **Password**, iyo **Role** (hal dropdown — ma jiraan tabs kala qaybsan).
- Nidaamku wuxuu ku wareejinayaa dashboard-ka doorkaaga ku habboon marka la xaqiijiyo.
- Haddii aad ilowday password-kaaga, "Forgot Password" waxay kuu diraysaa code Gmail ah.
- Marka aad markii ugu horreysay gasho (account cusub), waxaa lagu qasbayaa inaad **beddesho password-kaaga** ka hor intaadan dashboard-ka gaarin.
- **QR Code Scan**: haddii aad horey mobile-kaaga ugu xiratay (Profile & Password), waxaad ku geli kartaa QR scan la'aanta username/password.

---

## 2. Doorarka (Roles) — 6 Doorar

Nidaamku wuxuu leeyahay lix (6) doorar, mid kastaba awood/gudub (scope) gaar ah leh:

| # | Doorka | Gudubka (Scope) | Sida loo isticmaalo |
|---|---|---|---|
| 1 | **University Rector** | Nidaamka oo dhan — **eegis (view) kaliya** | Kormeeraha ugu sarreeya — wax walba wuu arkaa, laakiin wax kama beddelo (User Management + Settings ayaa keliya u furan) |
| 2 | **Head of Academic Affairs** | Faculty-yada oo dhan | Xilliyada (Semester), koorsooyinka, macalimiinta, iyo qaar ka mid ah User Management |
| 3 | **Registration Office** | Faculty-yada oo dhan (ardayda kaliya) | Diiwaan-gelinta ardayda (Add/Import/Download) |
| 4 | **Dean** | Faculty-gooda kaliya | Full CRUD Courses/Lecturers/Semesters, view-only kuwa kale |
| 5 | **Lecturer** | Koorsooyinkooda kaliya | Xaadirinta, Check-In/Out, Course Documents |
| 6 | **Student** | Diiwaankooda kaliya | Eegista xaadirinta, koorsooyinka, jadwalka |

**Fikrad guud**: doorka ugu sarreeya (Rector) wuxuu leeyahay awood ballaaran laakiin wax-qabad yar (view-only); doorarka hoose (Lecturer/Student) waxay leeyihiin gudub cidhiidhsan laakiin ku-dhaqan (action) buuxa gudubkooda gudihiisa.

---

## 3. Sidebar-ka — Sida ay u Kala Horreeyaan Qaybaha (Groups)

Side bar-ka (bidix) waxaa loo qaybiyay **kooxo (groups)**, mid kastaba isla habka isku xigxiga dhammaan doorarka — kaliya waxa isbeddela waa **doorka kee arkaya qayb kee**:

1. **Overview** — Dashboard (bogga koowaad ee doorkasta)
2. **Academic Management** — Students, Lecturers, Departments, Faculties, Courses, Semesters, Class Time Table, Import/Download Students, My Courses
3. **Attendance Management** — Attendance, Import Attendance, Lecturer Check-Ins/Check-In, My Attendance History
4. **Reports & Analytics** — Reports, Reports Hub, Teaching History
5. **Communication** — Notifications, Messages
6. **System Administration** — User Management, Settings, Audit Log (Rector kaliya)
7. **Account** — Profile & Password, Log Out

---

## 4. Habka Nidaamka loo Bilaabo (Bilowga — Setup)

Haddii jaamacad cusub la rabo in nidaamka lagu bilaabo, kani waa **habka rasmiga ah ee tallaabooyinka**:

### Tallaabo 1 — University Rector: Aasaaska Guud
1. **Settings** → geli magaca jaamacadda, campus-ka, email/phone-ka (waxay ku muuqan doonaan sky-blue strip-ka sare).
2. **Faculties** → abuur Faculty-yada (tusaale: Informatics, Health Sciences, Business Administration) — mid kastaba **Faculty Code** gaar ah ha lahaado (waxaa lagu isticmaalayaa credentials-ka ardayda).
3. **Academic Years** → abuur sanad-akadeemi (tusaale: 2025).
4. **User Management** → magacaabid Dean (+ Faculty), Head of Academic Affairs, ama Registration Office accounts.

### Tallaabo 2 — Head of Academic Affairs (ama Dean): Faculty-ga oo Dhis
1. **Departments** → abuur waaxyaha Faculty-ga (tusaale: Computer Science, Nursing).
2. **Semesters** → abuur Xilliga (Semester 1, 2, 3...) — geli **Start Date** (End Date + 12-ka Xiiso session si otomaatig ah ayaa loo buuxinayaa), kadibna **"Start"** ka dhig si uu u noqdo "Current".
3. **Courses** → abuur koorsooyinka, isla mar ahaantaana geli offering-kiisa ugu horreeya (Semester + Shift + Lecturer + Class Time Table — Day/Time/Room, ikhtiyaari).

### Tallaabo 3 — Registration Office: Diiwaan-gelinta Ardayda
1. **Import Students** → soo dejiso "Enrollment starter template", buuxi xogta ardayda (Student No, Student Name, Faculty, Department, Semester, Shift, iwm), kadibna soo geli file-ka (Preview → Confirm).
2. Sidoo kale waxaad ku dari kartaa arday hal-hal ah **Students** page-ka.
3. **Download Students** → mar walba waad soo dejin kartaa backup file ah ardayda hadda jira (waxaa loo isticmaali karaa dib-u-import marka la sameeyo Factory Reset).

### Tallaabo 4 — Dean/Head of Academic Affairs: U-qoondaynta Macalimiinta
1. **Lecturers** → diiwaan-geli macalimiinta (username/password otomaatig ah ayaa la sameeyaa).
2. **Manage Offerings** (ka gudbo Courses) ama **Assign Courses** → ku xir lecturer koorso + semester + shift gaar ah, kuna dar Class Time Table (Day/Time/Room) haddii la doonayo.

### Tallaabo 5 — Lecturer: Xaadirinta
1. **My Courses** → eeg koorsooyinka lagu magacaabay.
2. **Attendance** (Xiiso Grid) → dooro koorso + semester, kadibna kaliya taabo student-ka si aad Present/Absent ugu calaamadiso hal Xiiso session.
3. **Lecturer Check-In** → marka fasalka la bilaabo, "Check In" taabo; marka la dhammeeyo, "Check Out".
4. **Course Documents** → soo geli Chapter/Quiz/Assignment faylal ardayda u fidin karto.

### Tallaabo 6 — Student: Eegista
1. **Dashboard** → arag % xaadirinta (out of 10), koorsooyinka, iyo jadwalka.
2. **My Courses** → dooro Semester-ka (box picker), arag koorsooyinka + xaadirintaada.
3. **My Xiiso Grid** → arag saf-saf Xiiso 1-12 iyo calaamadaha.
4. **Class Time Table** → arag jadwalka maalinta/wakhtiga/qolka.
5. **Course Materials** → soo deji faylasha macalinku diray.

---

## 5. Qaybaha Muhiimka ah (Modules) — Sharaxaad Kooban

- **Attendance (Xaadirinta)**: waxaa lagu calaamadiyaa "Xiiso" (session), ma aha taariikh-ku-xir bannaan — 12 Xiiso semester kastaba, 6-aad = Midterm, 12-aad = Final (labadan ma xaadirsana). Buundo (score) waa **out-of-10** (tirada Xiiso ee Present ah, ma aha boqolkiiba).
- **Semesters/Xiiso**: xilliyada waa mid faculty kastaba **madax-banaan** — faculty kastaa wuxuu leeyahay Semester-kiisa "Current" gaarka ah, kuma xirna kuwa faculty-yada kale.
- **Course Offerings**: hal koorso wuxuu yeelan karaa dhawr "offering" (semester/shift/lecturer kala duwan) — xitaa lecturer kala duwan Morning iyo Afternoon.
- **Class Time Table**: Day/Time/Room ikhtiyaari ah ayaa lagu daraa offering kasta — waxaa la eegi karaa print-style bogg gooni ah.
- **Reports**: Course Attendance Summary, Department/Faculty Summary, Xiiso Attendance Grid — dhammaantood waxaa lagu soo dejin karaa **Excel** ama **PDF**.
- **Notifications**: marka arday ka hooseeyo heerka xaadirinta (default 75%, hadda 7.5/10), waxaa la "Notify" karaa.
- **Messages**: fariimo dhexdhexaad ah (Rector, Head Academic, Dean, Lecturer, Registration) — arday kuma jiraan.
- **Lecturer Check-In**: diiwaan gaar ah oo ka duwan xaadirinta ardayda — waxa uu tilmaamayaa in macalinku dhab ahaan fasalka joogay.
- **QR Code Login**: nidaam Whatsapp-Web u eg — la is-xidho mar hore, dabadeedna geli isticmaalka QR scan.

---

## 6. Xasuusin Muhiim ah

- **Xogtaada waa mid dhab ah** — mar walba samee **Download Students** ka hor Danger Zone Factory Reset.
- **Doorka University Rector** waa **view-only** meel kasta oo aan ahayn User Management/Settings — haddii wax bedelid loo baahdo, waxaa loo baahan yahay Head of Academic Affairs ama Dean.
- **Dean** wuxuu si buuxda wax uga bedeli karaa **faculty-giisa kaliya** — kuma arki karo, kumana beddeli karo xogta faculty-yada kale.
- **Password/Username** ardayda iyo macalimiinta waxaa si otomaatig ah loo sameeyaa (`FacultyCode-StudentNo` ama magaca-Staff-ID) — "Reset Password" ayaa mar kale soo saari kara haddii la ilaawo.

---

*Faylkan waxaa lagu sameeyay si loo caawiyo kobcinta iyo isticmaalka nidaamka ADMAS Attendance System.*
