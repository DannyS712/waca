﻿CREATE OR REPLACE VIEW interfacemessage AS SELECT mail_id as id, mail_text as content, mail_count as updatecounter, mail_desc as description, mail_type as type FROM sandbox_waca.acc_emails;