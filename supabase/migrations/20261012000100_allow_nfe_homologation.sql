alter table public.sync_cursors
  drop constraint sync_cursors_document_type_check,
  add constraint sync_cursors_document_type_check
    check (document_type in ('nfe55', 'nfe55_hom', 'cte57', 'nfse'));

alter table public.sync_runs
  drop constraint sync_runs_document_type_check,
  add constraint sync_runs_document_type_check
    check (document_type in ('nfe55', 'nfe55_hom', 'cte57', 'nfse'));
