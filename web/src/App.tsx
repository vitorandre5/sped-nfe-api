import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import type { FormEvent, ReactNode } from 'react'
import type { Session } from '@supabase/supabase-js'
import {
  ArrowDownToLine, ArrowRight, Building2, CalendarDays, Check, ChevronDown,
  CircleAlert, Clock3, FileSearch, FileText, Filter, LogOut, Plus,
  RefreshCw, Search, Settings, ShieldCheck, SlidersHorizontal, X,
} from 'lucide-react'
import { supabase } from './lib/supabase'
import type { Company, FiscalDocument } from './lib/supabase'

type Membership = { organization_id: string; role: string; organizations: { name: string } | null }
type Page = 'documents' | 'companies' | 'settings'
type Notice = { tone: 'success' | 'error' | 'info'; text: string } | null
type AutoSync = { documentType: 'all' | 'nfe55' | 'nfse'; companyId: string; file: File; password: string; nextSyncAt: string | null }
type CnpjRegistryProfile = Record<string, unknown>

const ALLOW_SIGNUP = import.meta.env.VITE_ALLOW_SIGNUP !== 'false'
const DEFAULT_USER_PASSWORD = 'teste123'
const FISCAL_CURSOR_TYPES = import.meta.env.VITE_NFE_TPAMB === '1' ? ['nfe55', 'nfse'] : ['nfe55_hom', 'nfse_hom']
const BRAZILIAN_STATES = [
  ['AC', 'Acre'], ['AL', 'Alagoas'], ['AP', 'Amapá'], ['AM', 'Amazonas'],
  ['BA', 'Bahia'], ['CE', 'Ceará'], ['DF', 'Distrito Federal'], ['ES', 'Espírito Santo'],
  ['GO', 'Goiás'], ['MA', 'Maranhão'], ['MT', 'Mato Grosso'], ['MS', 'Mato Grosso do Sul'],
  ['MG', 'Minas Gerais'], ['PA', 'Pará'], ['PB', 'Paraíba'], ['PR', 'Paraná'],
  ['PE', 'Pernambuco'], ['PI', 'Piauí'], ['RJ', 'Rio de Janeiro'], ['RN', 'Rio Grande do Norte'],
  ['RS', 'Rio Grande do Sul'], ['RO', 'Rondônia'], ['RR', 'Roraima'], ['SC', 'Santa Catarina'],
  ['SP', 'São Paulo'], ['SE', 'Sergipe'], ['TO', 'Tocantins'],
] as const

const normalizeTaxId = (value: string) => value.toUpperCase().replace(/[^A-Z0-9]/g, '')
const formatCnpj = (value: string) => {
  const raw = normalizeTaxId(value)
  if (/[A-Z]/.test(raw)) return raw.slice(0, 14)
  if (raw.length === 11) return raw.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4')
  const n = raw.slice(0, 14)
  return n.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
    .replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2')
}
const formatMoney = (value: number | null) => value == null
  ? '—'
  : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value)
const formatDate = (value: string | null) => value
  ? new Intl.DateTimeFormat('pt-BR').format(new Date(value))
  : '—'
const shortKey = (value: string | null) => value ? `${value.slice(0, 6)}…${value.slice(-4)}` : 'Resumo DF-e'
const documentLabel = (type: string) => type === 'nfse' ? 'NFS-e' : type === 'cte57' ? 'CT-e' : 'NF-e'

function App() {
  const [session, setSession] = useState<Session | null>(null)
  const [authReady, setAuthReady] = useState(false)
  const [page, setPage] = useState<Page>('documents')
  const [selectedCompany, setSelectedCompany] = useState('all')
  const [documentDirection, setDocumentDirection] = useState<'all' | 'purchase' | 'sale' | 'return' | 'transfer' | 'remittance_return' | 'bonus' | 'industrialization' | 'import_export'>('all')
  const [search, setSearch] = useState('')
  const [accountMenuOpen, setAccountMenuOpen] = useState(false)
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [companyDialog, setCompanyDialog] = useState(false)
  const [syncDialog, setSyncDialog] = useState(false)
  const [showOrganizationSetup, setShowOrganizationSetup] = useState(false)
  const [notice, setNotice] = useState<Notice>(null)
  const [busy, setBusy] = useState(false)
  const [autoSync, setAutoSync] = useState<AutoSync[]>([])
  const searchInput = useRef<HTMLInputElement>(null)
  const accountMenuRef = useRef<HTMLDivElement>(null)
  const autoSyncRunning = useRef(false)
  const queryClient = useQueryClient()

  useEffect(() => {
    if (!supabase) { setAuthReady(true); return }
    supabase.auth.getSession().then(({ data }) => {
      setSession(data.session)
      setAuthReady(true)
    })
    const { data: listener } = supabase.auth.onAuthStateChange((_event, value) => setSession(value))
    return () => listener.subscription.unsubscribe()
  }, [])

  useEffect(() => {
    if (!session) setAutoSync([])
  }, [session])

  useEffect(() => {
    if (!accountMenuOpen) return
    const closeOnOutsideClick = (event: MouseEvent) => {
      if (!accountMenuRef.current?.contains(event.target as Node)) setAccountMenuOpen(false)
    }
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setAccountMenuOpen(false)
    }
    window.addEventListener('pointerdown', closeOnOutsideClick)
    window.addEventListener('keydown', closeOnEscape)
    return () => {
      window.removeEventListener('pointerdown', closeOnOutsideClick)
      window.removeEventListener('keydown', closeOnEscape)
    }
  }, [accountMenuOpen])

  useLayoutEffect(() => {
    setSearch('')
    if (searchInput.current) searchInput.current.value = ''
  }, [session?.user.id])

  const { data: membership, isLoading: membershipLoading, error: membershipError } = useQuery({
    queryKey: ['membership', session?.user.id],
    enabled: Boolean(session?.user.id && supabase),
    queryFn: async () => {
      const { data, error } = await supabase!.from('organization_members')
        .select('organization_id, role, organizations(name)')
        .eq('user_id', session!.user.id).limit(1).maybeSingle()
      if (error) throw error
      return data as Membership | null
    },
  })

  const organizationId = membership?.organization_id

  useEffect(() => {
    const due = autoSync.filter((item) => item.nextSyncAt).sort((a, b) => Date.parse(a.nextSyncAt!) - Date.parse(b.nextSyncAt!))[0]
    if (!due || !session || !organizationId) return
    const delay = Math.max(0, Date.parse(due.nextSyncAt!) - Date.now())
    const timer = window.setTimeout(async () => {
      if (autoSyncRunning.current || !supabase) return
      autoSyncRunning.current = true
      try {
        const { data: sessionData, error: sessionError } = await supabase.auth.getSession()
        if (sessionError || !sessionData.session?.access_token) throw new Error('Entre novamente para retomar a sincronização automática.')
        const form = new FormData()
        form.set('payload', JSON.stringify({ organization_id: organizationId, company_id: due.companyId, document_type: due.documentType }))
        form.set('certificado', due.file, 'certificado.pfx')
        form.set('certificado_password', due.password)
        const response = await fetch('/api/nfe/sincronizar', {
          method: 'POST',
          headers: { Authorization: `Bearer ${sessionData.session.access_token}` },
          body: form,
        })
        const result = await response.json() as { success?: boolean; message?: string; data?: { message?: string; documentos_salvos?: number; next_sync_at?: string | null } }
        if (!response.ok || !result.success || !result.data) throw new Error(result.message ?? 'A sincronização automática falhou.')
        const count = result.data.documentos_salvos ?? 0
        setAutoSync((current) => current.map((item) => item.companyId === due.companyId && item.documentType === due.documentType
          ? { ...item, nextSyncAt: result.data!.next_sync_at ?? new Date(Date.now() + 60 * 60 * 1000).toISOString() }
          : item))
        await queryClient.invalidateQueries({ queryKey: ['documents', organizationId] })
        await queryClient.invalidateQueries({ queryKey: ['companies', organizationId] })
        await queryClient.invalidateQueries({ queryKey: ['sync-status', organizationId] })
        if (count > 0) {
          const text = `${count} novo${count === 1 ? '' : 's'} documento${count === 1 ? '' : 's'} fiscal${count === 1 ? '' : 'is'} recebido${count === 1 ? '' : 's'}.`
          setNotice({ tone: 'success', text })
          if ('Notification' in window && Notification.permission === 'granted') new Notification('Fiscal · novas notas', { body: text })
        }
      } catch (error) {
        setNotice({ tone: 'error', text: error instanceof Error ? error.message : 'Falha na sincronização automática.' })
        setAutoSync((current) => current.map((item) => item.companyId === due.companyId && item.documentType === due.documentType
          ? { ...item, nextSyncAt: new Date(Date.now() + 15 * 60 * 1000).toISOString() }
          : item))
      } finally {
        autoSyncRunning.current = false
      }
    }, delay)
    return () => window.clearTimeout(timer)
  }, [autoSync, session, organizationId, queryClient])

  const { data: companies = [], isLoading: companiesLoading } = useQuery({
    queryKey: ['companies', organizationId],
    enabled: Boolean(organizationId),
    queryFn: async () => {
      const { data, error } = await supabase!.from('companies').select('*')
        .eq('organization_id', organizationId!).order('name')
      if (error) throw error
      return data as Company[]
    },
  })

  const { data: syncCursors = [] } = useQuery({
    queryKey: ['sync-status', organizationId, selectedCompany],
    enabled: Boolean(organizationId),
    queryFn: async () => {
      let request = supabase!.from('sync_cursors').select('company_id,last_nsu,next_sync_at,last_run_at')
        .eq('organization_id', organizationId!).in('document_type', FISCAL_CURSOR_TYPES)
      if (selectedCompany !== 'all') request = request.eq('company_id', selectedCompany)
      const { data, error } = await request
      if (error) throw error
      return data ?? []
    },
  })

  const { data: documents = [], isLoading: documentsLoading, dataUpdatedAt } = useQuery({
    queryKey: ['documents', organizationId, selectedCompany, documentDirection, search, dateFrom, dateTo],
    enabled: Boolean(organizationId),
    queryFn: async () => {
      let request = supabase!.from('fiscal_documents')
        .select('*, company:companies(name,cnpj)', { count: 'exact' })
        .eq('organization_id', organizationId!)
        .order('issued_at', { ascending: false, nullsFirst: false })
        .range(0, 99)
      if (selectedCompany !== 'all') request = request.contains('source_company_ids', [selectedCompany])
      if (documentDirection === 'purchase') request = request.eq('is_purchase', true)
      if (documentDirection === 'sale') request = request.eq('is_sale', true)
      if (!['all', 'purchase', 'sale'].includes(documentDirection)) {
        request = request.contains('operation_categories', [documentDirection])
      }
      if (dateFrom) request = request.gte('issued_at', new Date(`${dateFrom}T00:00:00`).toISOString())
      if (dateTo) {
        const endExclusive = new Date(`${dateTo}T00:00:00`)
        endExclusive.setDate(endExclusive.getDate() + 1)
        request = request.lt('issued_at', endExclusive.toISOString())
      }
      const cleaned = search.trim().replace(/[^\p{L}\p{N} ]/gu, '').slice(0, 60)
      if (cleaned) {
        const pattern = `%${cleaned}%`
        request = request.or(`issuer_name.ilike.${pattern},recipient_name.ilike.${pattern},issuer_cnpj.ilike.${pattern},recipient_cnpj.ilike.${pattern},access_key.ilike.${pattern},document_number.ilike.${pattern}`)
      }
      const { data, error } = await request
      if (error) throw error
      return (data ?? []) as FiscalDocument[]
    },
  })

  const latestRun = useMemo(() => companies.filter((item) => selectedCompany === 'all' || item.id === selectedCompany).reduce<string | null>((latest, item) => {
    const time = (item as Company & { last_synced_at?: string }).last_synced_at
    return time && (!latest || time > latest) ? time : latest
  }, null), [companies, selectedCompany])

  const latestSyncStatus = useMemo(() => {
    const next = syncCursors.map((item) => item.next_sync_at).filter((value): value is string => Boolean(value))
      .sort((a, b) => b.localeCompare(a))[0]
    if (next && new Date(next).getTime() > Date.now()) {
      return `Aguardando liberacao ate ${new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(next))}`
    }
    return latestRun || syncCursors.some((item) => item.last_run_at) ? 'Pronta para nova consulta' : 'Ainda nao sincronizada'
  }, [syncCursors, latestRun])

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['documents', organizationId] })
  if (!supabase) return <SetupNotice />
  if (!authReady) return <div className="boot-screen"><div className="brand-mark"><img src="/fiscal-symbol-white.svg" alt="" /></div><span>Preparando seu espaço fiscal…</span></div>
  if (!session) return <AuthScreen onNotice={setNotice} />
  if (membershipLoading) return <div className="boot-screen"><div className="spinner" /><span>Carregando organização…</span></div>
  if (membershipError && !showOrganizationSetup) return <div className="setup-screen"><div className="setup-card"><span className="brand-mark"><Building2 size={20}/></span><h1>Não foi possível carregar sua organização</h1><p>Sua sessão pode ter expirado. Entre novamente para recuperar o acesso; se sua conta ainda não tiver uma organização, você poderá criar uma.</p><div className="organization-error-actions"><button className="button button-secondary" onClick={() => queryClient.invalidateQueries({ queryKey: ['membership'] })}>Tentar novamente</button><button className="button button-secondary" onClick={async () => { await supabase!.auth.signOut(); queryClient.clear() }}>Entrar novamente</button><button className="button button-primary" onClick={() => setShowOrganizationSetup(true)}>Criar organização<ArrowRight size={16}/></button></div></div></div>
  if (!membership || showOrganizationSetup) return <CreateOrganization user={session.user.email ?? ''} onNotice={setNotice} onBack={showOrganizationSetup ? () => setShowOrganizationSetup(false) : undefined} />

  const companyName = selectedCompany === 'all' ? 'Todas as empresas' : companies.find((item) => item.id === selectedCompany)?.name ?? 'Empresa'

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <a className="brand" href="#documentos" onClick={() => setPage('documents')}>
          <span className="brand-mark"><img src="/fiscal-symbol-white.svg" alt="" /></span>
          <span className="brand-name">fiscal<span>.</span></span>
        </a>
        <div className="org-switcher"><div className="org-avatar">{(membership.organizations?.name ?? 'O').slice(0, 1).toUpperCase()}</div><div className="org-copy"><small>Organização</small><strong>{membership.organizations?.name ?? 'Minha organização'}</strong></div><ChevronDown size={15} /></div>
        <div className="nav-label">ESPAÇO FISCAL</div>
        <nav className="main-nav" aria-label="Navegação principal">
          <button className={page === 'documents' ? 'nav-item active' : 'nav-item'} onClick={() => setPage('documents')}><FileSearch size={18} /><span>Documentos</span></button>
          <button className={page === 'companies' ? 'nav-item active' : 'nav-item'} onClick={() => setPage('companies')}><Building2 size={18} /><span>Empresas</span></button>
        </nav>
        <div className="sidebar-foot"><span className="connected-dot" /> Sistema conectado</div>
      </aside>

      <main className="main-area">
        <header className="topbar">
          <div className="mobile-brand"><span className="brand-mark"><img src="/fiscal-symbol-white.svg" alt="" /></span><b>fiscal<span>.</span></b></div>
          <div className="breadcrumb"><span>Workspace</span><span className="crumb-slash">/</span><strong>{page === 'documents' ? 'Documentos' : page === 'companies' ? 'Empresas' : 'Configurações'}</strong></div>
          <div className="top-actions">
            <span className="secure-label"><ShieldCheck size={15} /> Área protegida</span>
            <div className="account-menu" ref={accountMenuRef}>
              <button className="avatar-button" type="button" title={`Conta de ${session.user.email}`} aria-label="Abrir configurações da conta" aria-controls="account-menu-content" aria-expanded={accountMenuOpen} onClick={() => setAccountMenuOpen((open) => !open)}>{session.user.email?.slice(0, 1).toUpperCase()}</button>
              {accountMenuOpen && <div className="account-menu-popover" id="account-menu-content"><button type="button" onClick={() => { setPage('settings'); setAccountMenuOpen(false) }}><Settings size={16} />Configurações</button><button type="button" onClick={async () => { const { error } = await supabase!.auth.signOut(); if (error) { setNotice({ tone: 'error', text: 'Não foi possível sair da conta. Tente novamente.' }); return } queryClient.clear(); setAccountMenuOpen(false) }}><LogOut size={16} />Sair</button></div>}
            </div>
          </div>
        </header>

        <div className="content-wrap">
          {page === 'settings' ? (
            <>
              <section className="page-heading"><div><div className="eyebrow"><span className="eyebrow-line" /> CONTA</div><h1>Configurações</h1><p>Gerencie o acesso à sua conta.</p></div></section>
              <SettingsPage onNotice={setNotice} />
            </>
          ) : page === 'documents' ? (
            <>
              <section className="page-heading">
                <div><div className="eyebrow"><span className="eyebrow-line" /> CENTRAL DE DOCUMENTOS</div><h1>Busca fiscal</h1><p>Encontre notas e acompanhe o que chega para suas empresas.</p></div>
                <div className="heading-actions">{autoSync.length > 0 && <button className="button button-secondary" onClick={() => setAutoSync([])} title="Interromper a sincronização automática desta sessão">Automática ativa ({autoSync.length}) · Pausar</button>}<button className="button button-secondary" onClick={() => refresh()} disabled={documentsLoading}><RefreshCw size={16} className={documentsLoading ? 'spin' : ''} />Atualizar</button><button className="button button-primary" onClick={() => companies.length ? setSyncDialog(true) : setCompanyDialog(true)}><ArrowDownToLine size={17} />Sincronizar notas</button></div>
              </section>

              <section className="summary-grid" aria-label="Resumo dos documentos">
                <article className="summary-card"><div className="summary-icon green"><FileText size={18} /></div><div><span>Documentos encontrados</span><strong>{documents.length}</strong><small>{documents.length === 100 ? 'Mostrando os 100 mais recentes' : 'Nesta busca'}</small></div></article>
                <article className="summary-card"><div className="summary-icon blue"><Building2 size={18} /></div><div><span>Empresa selecionada</span><strong className="summary-company">{companies.length ? companyName : 'Nenhuma empresa'}</strong><small>{companies.length} {companies.length === 1 ? 'empresa conectada' : 'empresas conectadas'}</small></div></article>
                <article className="summary-card"><div className="summary-icon amber"><Clock3 size={18} /></div><div><span>Última consulta</span><strong className="summary-company">{latestRun ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(latestRun)) : 'Ainda não consultado'}</strong><small>{latestSyncStatus}</small></div></article>
              </section>

              <section className="search-panel">
                <div className="search-row">
                  <label className="search-input"><Search size={18} /><span className="sr-only">Buscar documentos</span><input ref={searchInput} type="search" name="fiscal-document-search" autoComplete="off" autoCorrect="off" spellCheck={false} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar por emitente, destinatário, CNPJ ou chave…" /></label>
                  <label className="company-select"><Building2 size={16} /><span className="sr-only">Filtrar por empresa</span><select value={selectedCompany} onChange={(event) => setSelectedCompany(event.target.value)}><option value="all">Todas as empresas</option>{companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}</select><ChevronDown size={15} /></label>
                </div>
                <div className="filter-row"><div className="filter-caption"><SlidersHorizontal size={15} /><span>Filtros</span></div><label className="date-filter"><CalendarDays size={15} /><span>De</span><input type="date" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} /></label><label className="date-filter"><CalendarDays size={15} /><span>Até</span><input type="date" value={dateTo} onChange={(event) => setDateTo(event.target.value)} /></label><label className="date-filter direction-filter"><span>Tipo</span><select value={documentDirection} onChange={(event) => setDocumentDirection(event.target.value as typeof documentDirection)}><option value="all">Tudo</option><option value="purchase">Compras</option><option value="sale">Vendas</option><option value="return">Devolução</option><option value="transfer">Transferência entre filiais</option><option value="remittance_return">Remessa e retorno</option><option value="bonus">Bonificação</option><option value="industrialization">Industrialização</option><option value="import_export">Importação/exportação</option></select></label><span className="type-pill"><span className="type-dot" /> NF-e e NFS-e Nacional</span><span className="updated-at">{dataUpdatedAt ? `Atualizado às ${new Intl.DateTimeFormat('pt-BR', { timeStyle: 'short' }).format(dataUpdatedAt)}` : 'Aguardando consulta'}</span></div>
              </section>

              <section className="results-card">
                <div className="results-heading"><div><h2>Documentos fiscais</h2><p>{documents.length} resultado{documents.length === 1 ? '' : 's'} nesta página</p></div><button className="icon-button" title="Limpar filtros" onClick={() => { setSearch(''); setDateFrom(''); setDateTo(''); setSelectedCompany('all'); setDocumentDirection('all') }}><Filter size={17} /><span className="sr-only">Limpar filtros</span></button></div>
                {documentsLoading ? <div className="loading-state"><div className="spinner" /><span>Buscando documentos…</span></div> : documents.length ? <DocumentTable documents={documents} onOpen={(document) => { void openDocumentXml(document).catch((error) => setNotice({ tone: 'error', text: error instanceof Error ? error.message : 'Não foi possível abrir o XML.' })) }} /> : <EmptyDocuments hasCompanies={companies.length > 0} onAddCompany={() => setCompanyDialog(true)} onSync={() => setSyncDialog(true)} />}
              </section>
              <footer className="content-footer"><span>Dados isolados por organização e empresa</span></footer>
            </>
          ) : (
            <CompaniesPage companies={companies} loading={companiesLoading} onAdd={() => setCompanyDialog(true)} onSync={(id) => { setSelectedCompany(id); setSyncDialog(true) }} />
          )}
        </div>
      </main>

      {companyDialog && <CompanyDialog organizationId={organizationId!} onClose={() => setCompanyDialog(false)} onSaved={async () => { setCompanyDialog(false); await queryClient.invalidateQueries({ queryKey: ['companies', organizationId] }); setNotice({ tone: 'success', text: 'Empresa adicionada.' }) }} onNotice={setNotice} />}
      {syncDialog && <SyncDialog companies={companies} initialCompanyId={selectedCompany === 'all' ? companies[0]?.id : selectedCompany} organizationId={organizationId!} onClose={() => setSyncDialog(false)} onNotice={setNotice} onBusy={setBusy} onEnableAuto={(value) => setAutoSync((current) => [...current.filter((item) => item.companyId !== value.companyId), value])} onDone={async () => { setSyncDialog(false); await queryClient.invalidateQueries({ queryKey: ['companies', organizationId] }); await queryClient.invalidateQueries({ queryKey: ['documents', organizationId] }); await queryClient.invalidateQueries({ queryKey: ['sync-status', organizationId] }) }} />}
      {notice && <div className={`toast ${notice.tone}`} role="status"><span>{notice.tone === 'error' ? <CircleAlert size={17} /> : <Check size={17} />}</span>{notice.text}<button onClick={() => setNotice(null)} aria-label="Fechar aviso"><X size={15} /></button></div>}
      {busy && <div className="busy-scrim" aria-live="polite">Sincronizando notas fiscais…</div>}
    </div>
  )
}

function SetupNotice() {
  return <div className="setup-screen"><div className="setup-card"><span className="brand-mark"><img src="/fiscal-symbol-white.svg" alt="" /></span><p className="eyebrow">SISTEMA FISCAL</p><h1>Serviço indisponível</h1><p>Não foi possível conectar ao sistema agora. Atualize a página e, se o problema continuar, fale com o suporte.</p></div></div>
}

function AuthScreen({ onNotice }: { onNotice: (notice: Notice) => void }) {
  const [isSignup, setIsSignup] = useState(false)
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [working, setWorking] = useState(false)
  const [message, setMessage] = useState('')
  const submit = async (event: FormEvent) => {
    event.preventDefault(); if (!supabase) return
    setWorking(true); setMessage('')
    try {
      if (isSignup) {
        const { data, error } = await supabase.auth.signUp({
          email,
          password: DEFAULT_USER_PASSWORD,
        })
        if (error) throw error
        if (data.session) setMessage('Conta criada. Você já pode entrar.')
        else setMessage('Conta criada. Confirme o e-mail para entrar.')
      } else {
        const { error } = await supabase.auth.signInWithPassword({ email, password })
        if (error) throw error
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Não foi possível autenticar.'
      setMessage(`Erro: ${message}`)
      onNotice({ tone: 'error', text: message })
    }
    finally { setWorking(false) }
  }
  return <div className="auth-screen"><div className="auth-decoration"><div className="auth-orbit orbit-one"/><div className="auth-orbit orbit-two"/><div className="auth-brand"><span className="brand-mark"><img src="/fiscal-symbol-white.svg" alt="" /></span><b>fiscal<span>.</span></b></div><div className="auth-manifesto"><span className="eyebrow">SEU ARQUIVO FISCAL</span><h1>Documentos em ordem.<br/><em>Decisões com clareza.</em></h1><p>Uma visão organizada das notas fiscais que chegam para suas empresas.</p></div><div className="auth-decoration-foot"><span>NF-e · modelo 55</span><span>Ambiente protegido</span></div></div><div className="auth-form-side"><form className="auth-form" onSubmit={submit}><span className="eyebrow">{isSignup ? 'PRIMEIRO ACESSO' : 'BEM-VINDO DE VOLTA'}</span><h2>{isSignup ? 'Crie sua conta' : 'Entre na sua conta'}</h2><p>Acesse seu espaço fiscal para continuar.</p><label className="field-label">E-mail<input type="email" autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} required /></label>{isSignup ? <div className="dialog-note"><ShieldCheck size={16}/>As senhas das contas são gerenciadas no Supabase.</div> : <label className="field-label">Senha<input type="password" autoComplete="current-password" minLength={8} value={password} onChange={(event) => setPassword(event.target.value)} required /></label>}{message && <div className={`inline-message ${message.startsWith('Erro:') ? 'error' : ''}`} role="status">{message}</div>}<button className="button button-primary auth-submit" disabled={working}>{working ? <><span className="button-spinner"/>Processando…</> : <>{isSignup ? 'Criar conta' : 'Entrar'}<ArrowRight size={16}/></>}</button>{ALLOW_SIGNUP && <button type="button" className="text-button" onClick={() => { setIsSignup(!isSignup); setPassword(''); setMessage('') }}>{isSignup ? 'Já tem conta? Entrar' : 'Ainda não tem conta? Criar acesso'}</button>}<div className="auth-privacy"><ShieldCheck size={16}/>Seus dados fiscais ficam protegidos.</div></form></div></div>
}

function SettingsPage({ onNotice }: { onNotice: (notice: Notice) => void }) {
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [working, setWorking] = useState(false)
  const [message, setMessage] = useState('')

  const changePassword = async (event: FormEvent) => {
    event.preventDefault()
    if (!supabase) return
    if (password.length < 8) { setMessage('Use pelo menos 8 caracteres na nova senha.'); return }
    if (password === DEFAULT_USER_PASSWORD) { setMessage('Escolha uma senha pessoal diferente da senha inicial.'); return }
    if (password !== confirmation) { setMessage('As senhas não coincidem.'); return }

    setWorking(true)
    setMessage('')
    try {
      const { error } = await supabase.auth.updateUser({ password })
      if (error) throw error
      setPassword('')
      setConfirmation('')
      onNotice({ tone: 'success', text: 'Senha alterada com sucesso.' })
    } catch {
      const text = 'Não foi possível alterar a senha. Confira os requisitos e tente novamente.'
      setMessage(text)
      onNotice({ tone: 'error', text })
    } finally {
      setWorking(false)
    }
  }

  return <section className="companies-card settings-card"><form className="dialog-form settings-form" onSubmit={changePassword}><p>Altere a senha usada para entrar na sua conta. Use pelo menos 8 caracteres.</p><label className="field-label">Nova senha<input type="password" autoComplete="new-password" minLength={8} value={password} onChange={(event) => setPassword(event.target.value)} required /></label><label className="field-label">Confirme a nova senha<input type="password" autoComplete="new-password" minLength={8} value={confirmation} onChange={(event) => setConfirmation(event.target.value)} required /></label>{message && <div className="inline-message error" role="alert">{message}</div>}<div className="settings-actions"><button className="button button-primary" disabled={working}>{working ? 'Salvando…' : 'Salvar senha'}<ArrowRight size={16} /></button></div></form></section>
}

function CreateOrganization({ user, onNotice, onBack }: { user: string; onNotice: (notice: Notice) => void; onBack?: () => void }) {
  const [name, setName] = useState('')
  const [working, setWorking] = useState(false)
  const [errorMessage, setErrorMessage] = useState('')
  const queryClient = useQueryClient()
  const create = async (event: FormEvent) => {
    event.preventDefault(); if (!supabase) return
    setWorking(true); setErrorMessage('')
    try {
      const { error } = await supabase.rpc('create_organization', { org_name: name.trim() })
      if (error) throw error
      await queryClient.invalidateQueries({ queryKey: ['membership'] })
      onNotice({ tone: 'success', text: 'Organização criada.' })
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Não foi possível criar a organização.'
      setErrorMessage(message)
      onNotice({ tone: 'error', text: message })
    } finally { setWorking(false) }
  }
  return <div className="setup-screen"><form className="setup-card organization-setup" onSubmit={create}><span className="brand-mark"><Building2 size={20}/></span><p className="eyebrow">UM ESPAÇO PARA {user.toUpperCase()}</p><h1>Crie sua organização</h1><p>Empresas e documentos ficam agrupados neste espaço.</p><label className="field-label">Nome da organização<input autoFocus value={name} onChange={(event) => setName(event.target.value)} placeholder="Ex.: Meu escritório" required maxLength={100}/></label>{errorMessage && <div className="inline-message error" role="alert">{errorMessage}</div>}{onBack ? <div className="organization-error-actions"><button type="button" className="button button-secondary" onClick={onBack}>Voltar</button><button className="button button-primary" disabled={working}>{working ? 'Criando…' : 'Continuar'}<ArrowRight size={16}/></button></div> : <button className="button button-primary" disabled={working}>{working ? 'Criando…' : 'Continuar'}<ArrowRight size={16}/></button>}</form></div>
}

function CompanyDialog({ organizationId, onClose, onSaved, onNotice }: { organizationId: string; onClose: () => void; onSaved: () => void; onNotice: (notice: Notice) => void }) {
  const [name, setName] = useState(''); const [cnpj, setCnpj] = useState(''); const [state, setState] = useState('MG'); const [working, setWorking] = useState(false)
  const [registryData, setRegistryData] = useState<CnpjRegistryProfile | null>(null)
  const [registryMessage, setRegistryMessage] = useState('')
  const [registryLoading, setRegistryLoading] = useState(false)
  const nameEdited = useRef(false)
  const stateEdited = useRef(false)

  useEffect(() => {
    const normalized = normalizeTaxId(cnpj)
    if (!/^[A-Z0-9]{12}[0-9]{2}$/.test(normalized)) {
      setRegistryData(null)
      setRegistryMessage('')
      setRegistryLoading(false)
      return
    }

    let active = true
    setRegistryData(null)
    setRegistryMessage('')
    setRegistryLoading(true)
    const timer = window.setTimeout(async () => {
      if (!supabase) { setRegistryLoading(false); return }
      try {
        const { data: sessionData, error: sessionError } = await supabase.auth.getSession()
        if (sessionError || !sessionData.session?.access_token) throw new Error('Entre novamente para consultar os dados públicos.')
        const response = await fetch('/api/cnpj/consultar', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${sessionData.session.access_token}` },
          body: JSON.stringify({ organization_id: organizationId, cnpj: normalized }),
        })
        const result = await response.json() as { success?: boolean; data?: { found?: boolean; data?: CnpjRegistryProfile | null; message?: string }; message?: string }
        if (!response.ok || !result.success || !result.data) throw new Error(result.message ?? 'Não foi possível consultar os dados públicos.')
        if (!active) return
        if (result.data.found && result.data.data) {
          const profile = result.data.data
          setRegistryData(profile)
          const legalName = typeof profile.nome_empresarial === 'string' ? profile.nome_empresarial : ''
          const registeredState = typeof profile.uf === 'string' ? profile.uf.toUpperCase() : ''
          if (legalName && !nameEdited.current) setName(legalName.slice(0, 160))
          if (/^[A-Z]{2}$/.test(registeredState) && !stateEdited.current) setState(registeredState)
        } else {
          setRegistryData(null)
        }
        setRegistryMessage(result.data.message ?? '')
      } catch (error) {
        if (active) {
          setRegistryData(null)
          setRegistryMessage(error instanceof Error ? `${error.message} O cadastro manual continua disponível.` : 'Consulta indisponível. O cadastro manual continua disponível.')
        }
      } finally {
        if (active) setRegistryLoading(false)
      }
    }, 550)

    return () => { active = false; window.clearTimeout(timer) }
  }, [cnpj, organizationId])

  const save = async (event: FormEvent) => {
    event.preventDefault(); if (!supabase) return
    if (registryLoading) { onNotice({ tone: 'info', text: 'Aguarde a consulta pública terminar; se estiver indisponível, o cadastro manual será liberado.' }); return }
    const normalized = normalizeTaxId(cnpj)
    if (!/^[A-Z0-9]{12}[0-9]{2}$/.test(normalized)) { onNotice({ tone: 'error', text: 'Informe CNPJ com 14 caracteres validos.' }); return }
    setWorking(true)
    try {
      const { error } = await supabase.from('companies').insert({
        organization_id: organizationId,
        name: name.trim(),
        cnpj: normalized,
        state,
        cnpj_registry_data: registryData ?? {},
        cnpj_registry_fetched_at: registryData ? new Date().toISOString() : null,
      })
      if (error) throw error
      onSaved()
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Não foi possível salvar a empresa.'
      const code = typeof error === 'object' && error !== null && 'code' in error ? (error as { code?: string }).code : undefined
      onNotice({ tone: 'error', text: code === '23505' ? 'Este CNPJ já está cadastrado nesta organização.' : message })
    } finally { setWorking(false) }
  }
  return <Modal title="Adicionar empresa" description="Informe o CNPJ para localizar e preencher os dados cadastrais públicos." onClose={onClose}><form className="dialog-form" onSubmit={save}><label className="field-label">CNPJ<input autoFocus inputMode="text" autoCapitalize="characters" value={formatCnpj(cnpj)} onChange={(event) => setCnpj(normalizeTaxId(event.target.value).slice(0, 14))} required placeholder="00.000.000/0000-00"/>{(registryLoading || registryMessage) && <small className="cnpj-lookup-status" role="status">{registryLoading ? 'Consultando cadastro público…' : registryMessage}</small>}</label><label className="field-label">Nome empresarial<input value={name} onChange={(event) => { nameEdited.current = true; setName(event.target.value) }} required maxLength={160} placeholder="Razão social"/></label><label className="field-label">UF do estabelecimento<select value={state} onChange={(event) => { stateEdited.current = true; setState(event.target.value) }} required>{BRAZILIAN_STATES.map(([code, label]) => <option key={code} value={code}>{code} · {label}</option>)}</select></label>{registryData && <div className="dialog-note"><ShieldCheck size={16}/><span>Cadastro localizado: {String(registryData.situacao_cadastral_descricao ?? 'situação não informada')}{registryData.cnae_fiscal_principal_descricao ? ` · CNAE ${String(registryData.cnae_fiscal_principal_descricao)}` : ''}. Confira o nome e a UF antes de salvar.</span></div>}<div className="dialog-note"><ShieldCheck size={16}/>A consulta é auxiliar, pode estar desatualizada e não substitui a conferência de IE, CRT ou demais dados fiscais. O certificado A1 será solicitado ao sincronizar.</div><div className="dialog-actions"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary" disabled={working}>{working ? 'Salvando…' : 'Adicionar empresa'}</button></div></form></Modal>
}

function SyncDialog({ companies, initialCompanyId, organizationId, onClose, onNotice, onBusy, onEnableAuto, onDone }: { companies: Company[]; initialCompanyId?: string; organizationId: string; onClose: () => void; onNotice: (notice: Notice) => void; onBusy: (busy: boolean) => void; onEnableAuto: (value: AutoSync) => void; onDone: () => void }) {
  const [companyId, setCompanyId] = useState(initialCompanyId ?? '')
  const [documentType, setDocumentType] = useState<'all' | 'nfe55' | 'nfse'>('all')
  const [specificNsu, setSpecificNsu] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [password, setPassword] = useState('')
  const [working, setWorking] = useState(false)
  const [autoEnabled, setAutoEnabled] = useState(false)
  const [phase, setPhase] = useState('')
  const certificateInput = useRef<HTMLInputElement>(null)
  const company = companies.find((item) => item.id === companyId)

  const sync = async (event: FormEvent) => {
    event.preventDefault(); if (!supabase || !company || !file) return
    if (!file.name.toLowerCase().endsWith('.pfx') && !file.name.toLowerCase().endsWith('.p12')) { onNotice({ tone: 'error', text: 'Selecione um certificado A1 .pfx ou .p12.' }); return }
    setWorking(true); onBusy(true); setPhase('Validando sessao e preparando consulta segura...')
    try {
      const { data: sessionData, error: sessionError } = await supabase.auth.getSession()
      if (sessionError || !sessionData.session?.access_token) throw new Error('Entre novamente para sincronizar documentos.')
      const form = new FormData()
      form.set('payload', JSON.stringify({ organization_id: organizationId, company_id: company.id, document_type: documentType, ...(documentType === 'nfe55' && specificNsu ? { num_nsu: specificNsu } : {}) }))
      form.set('certificado', file, 'certificado.pfx')
      form.set('certificado_password', password)
      setPhase('Consultando a distribuicao oficial e salvando documentos...')
      const response = await fetch('/api/nfe/sincronizar', {
        method: 'POST',
        headers: { Authorization: `Bearer ${sessionData.session.access_token}` },
        body: form,
      })
      const result = await response.json() as { success?: boolean; message?: string; data?: { message?: string; documentos_salvos?: number; next_sync_at?: string | null } }
      if (!response.ok || !result.success || !result.data) throw new Error(result.message ?? 'Nao foi possivel concluir a sincronizacao.')
      const count = result.data.documentos_salvos ?? 0
      const next = result.data.next_sync_at ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(result.data.next_sync_at)) : null
      onNotice({ tone: 'success', text: `${result.data.message ?? 'Sincronizacao concluida.'} ${count} documento${count === 1 ? '' : 's'} recebido${count === 1 ? '' : 's'}${next ? ` Proxima consulta a partir de ${next}.` : ''}` })
      if (autoEnabled && !specificNsu) onEnableAuto({ documentType, companyId: company.id, file, password, nextSyncAt: result.data.next_sync_at ?? null })
      onDone()
    } catch (error) {
      onNotice({ tone: 'error', text: error instanceof Error ? error.message : 'Falha ao sincronizar os documentos.' })
    } finally {
      setWorking(false); onBusy(false); setPhase(''); setPassword(''); setFile(null)
      if (certificateInput.current) certificateInput.current.value = ''
    }
  }

  return <Modal title="Sincronizar notas" description="Consulta oficial. Certificado e senha sao processados no servidor durante esta solicitacao." onClose={onClose}><form className="dialog-form" onSubmit={sync}><div className="dialog-note"><ShieldCheck size={16}/>Consulte NF-e na SEFAZ e notas de serviço no Ambiente Nacional da NFS-e.</div><label className="field-label">Empresa<select value={companyId} onChange={(event) => setCompanyId(event.target.value)} required><option value="">Selecione a empresa</option>{companies.map((item) => <option key={item.id} value={item.id}>{item.name} - {formatCnpj(item.cnpj)}</option>)}</select></label><label className="field-label">Documentos<select value={documentType} onChange={(event) => setDocumentType(event.target.value as typeof documentType)}><option value="all">NF-e e NFS-e Nacional</option><option value="nfe55">NF-e · SEFAZ</option><option value="nfse">NFS-e · Ambiente Nacional</option></select></label>{documentType === 'nfe55' && <label className="field-label">NSU específico (opcional)<input inputMode="numeric" value={specificNsu} onChange={(event) => setSpecificNsu(event.target.value.replace(/\D/g, '').slice(0, 15))} placeholder="Deixe vazio para buscar novas notas"/><small>Permite recuperar uma nota já recebida por outro sistema. O histórico de distribuição não é alterado.</small></label>}<label className="field-label">Certificado digital A1<input ref={certificateInput} type="file" accept=".pfx,.p12,application/x-pkcs12" onChange={(event) => setFile(event.target.files?.[0] ?? null)} required/><small>O arquivo vai diretamente para a API fiscal e nao fica salvo no navegador.</small></label><label className="field-label">Senha do certificado<input type="password" autoComplete="new-password" value={password} onChange={(event) => setPassword(event.target.value)} required/></label><label className="field-label checkbox-field"><input type="checkbox" checked={autoEnabled} onChange={(event) => setAutoEnabled(event.target.checked)}/>Ativar sincronizacao automatica nesta sessao</label><small>Enquanto esta tela permanecer aberta e conectada, o sistema retoma no horário liberado por cada serviço. O certificado e a senha ficam somente na memoria desta sessao. Para avisos fora da pagina, permita notificacoes do navegador.</small><div className="dialog-note"><ShieldCheck size={16}/>Cada serviço mantém seu próprio histórico de consulta. Nenhuma manifestação fiscal é enviada automaticamente.</div>{working && <div className="sync-progress"><span className="spinner"/>{phase}</div>}<div className="dialog-actions"><button type="button" className="button button-secondary" onClick={onClose} disabled={working}>Cancelar</button><button className="button button-primary" disabled={working || !file || !companyId}>{working ? "Sincronizando..." : "Iniciar consulta"}<ArrowRight size={16}/></button></div></form></Modal>
}

function DocumentTable({ documents, onOpen }: { documents: FiscalDocument[]; onOpen: (document: FiscalDocument) => void }) {
  return <div className="table-scroll"><table><thead><tr><th>Documento</th><th>Emitente</th><th>Destinatário</th><th>Emissão</th><th>Valor</th><th>Situação</th><th><span className="sr-only">Abrir XML</span></th></tr></thead><tbody>{documents.map((item) => <tr key={item.id} onClick={() => onOpen(item)} tabIndex={0} onKeyDown={(event) => { if (event.key === 'Enter') onOpen(item) }}><td><div className="document-cell"><span className="doc-icon"><FileText size={16}/></span><span><strong>{item.document_number ? `${documentLabel(item.document_type)} ${item.document_number}` : `${documentLabel(item.document_type)} recebida`}</strong><small>{shortKey(item.access_key)} · NSU {item.nsu}</small></span></div></td><td><div className="party-cell"><strong>{item.issuer_name ?? 'Emitente não identificado'}</strong><small>{formatCnpj(item.issuer_cnpj ?? '')}</small></div></td><td><div className="party-cell"><strong>{item.recipient_name ?? 'Destinatário não identificado'}</strong><small>{formatCnpj(item.recipient_cnpj ?? '')}</small></div></td><td className="date-cell">{formatDate(item.issued_at)}</td><td className="amount-cell">{formatMoney(item.total_amount)}</td><td><span className="status-badge"><span/> {item.situation}</span></td><td><button className="row-action" aria-label="Abrir XML" onClick={(event) => { event.stopPropagation(); onOpen(item) }}><ArrowRight size={16}/></button></td></tr>)}</tbody></table></div>
}

async function openDocumentXml(document: FiscalDocument) {
  if (!supabase) return
  const filename = `${documentLabel(document.document_type).toLowerCase().replace(/-/g, '')}-${document.access_key ?? document.id}.xml`
  const { data, error } = await supabase.storage.from('fiscal-xml').download(document.storage_path)
  if (error) throw error
  const url = URL.createObjectURL(data)
  const link = window.document.createElement('a')
  link.href = url
  link.download = filename
  link.rel = 'noopener noreferrer'
  window.document.body.appendChild(link)
  link.click()
  link.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 1000)
}

function EmptyDocuments({ hasCompanies, onAddCompany, onSync }: { hasCompanies: boolean; onAddCompany: () => void; onSync: () => void }) {
  return <div className="empty-state"><div className="empty-art"><div className="empty-sheet sheet-back"/><div className="empty-sheet sheet-front"><span/><span/><span/></div><div className="empty-search"><Search size={18}/></div></div><h3>{hasCompanies ? 'Sua caixa fiscal está vazia' : 'Comece adicionando uma empresa'}</h3><p>{hasCompanies ? 'Sincronize as notas de produtos e serviços das suas empresas.' : 'Cadastre um CNPJ para organizar os documentos fiscais recebidos.'}</p><button className="button button-primary" onClick={hasCompanies ? onSync : onAddCompany}>{hasCompanies ? <><ArrowDownToLine size={16}/>Sincronizar notas</> : <><Plus size={16}/>Adicionar empresa</>}</button></div>
}

function CompaniesPage({ companies, loading, onAdd, onSync }: { companies: Company[]; loading: boolean; onAdd: () => void; onSync: (id: string) => void }) {
  return <><section className="page-heading"><div><div className="eyebrow"><span className="eyebrow-line"/> ORGANIZAÇÃO</div><h1>Empresas</h1><p>Gerencie os CNPJs conectados à sua busca fiscal.</p></div><button className="button button-primary" onClick={onAdd}><Plus size={17}/>Adicionar empresa</button></section><section className="companies-card"><div className="results-heading"><div><h2>Empresas cadastradas</h2><p>{companies.length} CNPJ{companies.length === 1 ? '' : 's'} nesta organização</p></div></div>{loading ? <div className="loading-state"><div className="spinner"/><span>Carregando empresas…</span></div> : companies.length ? <div className="company-list">{companies.map((company) => <article className="company-row" key={company.id}><div className="company-symbol"><Building2 size={19}/></div><div className="company-details"><strong>{company.name}</strong><span>{formatCnpj(company.cnpj)}<i/> {company.state}</span></div><div className="company-connector"><span className="connector-dot"/>NF-e 55</div><button className="button button-secondary button-small" onClick={() => onSync(company.id)}><RefreshCw size={14}/>Sincronizar</button></article>)}</div> : <EmptyDocuments hasCompanies={false} onAddCompany={onAdd} onSync={onAdd}/>}</section><footer className="content-footer"><span>O escopo de cada consulta respeita o CNPJ cadastrado.</span><span><span className="footer-dot"/> Serviço conectado</span></footer></>
}

function Modal({ title, description, onClose, children }: { title: string; description: string; onClose: () => void; children: ReactNode }) {
  useEffect(() => { const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose() }; window.addEventListener('keydown', onKey); return () => window.removeEventListener('keydown', onKey) }, [onClose])
  return <div className="modal-backdrop" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}><section className="modal-card" role="dialog" aria-modal="true" aria-labelledby="modal-title"><div className="modal-heading"><div><h2 id="modal-title">{title}</h2><p>{description}</p></div><button className="icon-button" onClick={onClose} aria-label="Fechar janela"><X size={18}/></button></div>{children}</section></div>
}

export default App
