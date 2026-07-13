# Kelime Oyunu – Başlık Bazlı Ortak Soru Havuzu

Bu sürümde kelime oyunu soruları yeterliliklere ayrı ayrı girilmez. Her soru yalnızca bir **başlığa** bağlıdır.

## Yeni çalışma mantığı

- Başlık, soru havuzudur.
- Yeterlilikler başlıklara bağlanır.
- Bir yeterlilik birden fazla başlığa bağlanabilir.
- Kullanıcı oyunu başlattığında aktif yeterliliğine bağlı tüm aktif başlıkların soruları birleşik havuz olarak kullanılır.
- Aynı cevap farklı başlıklarda bulunuyorsa aynı oyun oturumunda yalnızca bir kez seçilir.
- Admin tekli ve toplu soru girişinde yeterlilik seçmez.
- Aynı başlık içinde aynı normalize cevap yeniden eklenemez.
- Toplu girişte bir kayıt hatalıysa hiçbir kayıt eklenmez.

## Değişen dosyalar

- `includes/word_game_question_helper.php`
- `includes/word_game_helper.php`
- `ajax/word-game-questions.php`
- `api/v1/word-game/start.php`
- `pages/word-game-questions.php`
- `pages/word-game-categories.php`
- `pages/word-game-mappings.php`

## Kurulum

1. Önce mevcut dosyaların ve veritabanının yedeğini alın.
2. `MANUEL_SQL_KELIME_OYUNU_BASLIK_HAVUZU.sql` dosyasını phpMyAdmin üzerinden bir kez çalıştırın.
3. Proje dosyalarını sunucuya yükleyin.
4. Admin panelde başlık–yeterlilik eşleştirmelerini kontrol edin.
5. Kelime oyunu soru girişinde yeterlilik alanının kaldırıldığını doğrulayın.
6. Uygulamada farklı başlıklara bağlı bir yeterlilikle oyun başlatıp birleşik havuzu test edin.

## Not

SQL çalıştırılmadan yeni soru eklenirse `qualification_id` alanı eski veritabanında zorunlu olduğu için kayıt işlemi başarısız olabilir. Bu nedenle SQL adımı zorunludur.
