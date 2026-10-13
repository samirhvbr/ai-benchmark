-- CondoDesk schema (PostgreSQL 16 or newer).

create table building (
    id                   bigserial primary key,
    name                 text not null,
    address              text not null,
    time_zone            text not null default 'America/Sao_Paulo',
    due_day              smallint not null default 10 check (due_day between 1 and 28),
    late_fee_pct         numeric(5, 2) not null default 2.00,
    monthly_interest_pct numeric(5, 2) not null default 1.00
);

create table unit (
    id             bigserial primary key,
    building_id    bigint not null references building (id),
    label          text not null,
    ideal_fraction numeric(9, 6) not null check (ideal_fraction > 0),
    unique (building_id, label)
);

create table app_user (
    id            bigserial primary key,
    email         text not null unique,
    name          text not null,
    role          text not null check (role in ('ADMIN', 'MANAGER', 'RESIDENT')),
    building_id   bigint references building (id),
    password_hash text not null,
    active        boolean not null default true,
    created_at    timestamptz not null
);

create table residency (
    user_id bigint not null references app_user (id),
    unit_id bigint not null references unit (id),
    kind    text not null check (kind in ('OWNER', 'TENANT')),
    primary key (user_id, unit_id)
);

-- A browser session. user_id is null while the visitor has not logged in yet (the login form needs a CSRF token too).
create table user_session (
    id_hash      text primary key,
    user_id      bigint references app_user (id),
    csrf_token   text not null,
    created_at   timestamptz not null,
    last_seen_at timestamptz not null
);

create table api_token (
    id           bigserial primary key,
    user_id      bigint not null references app_user (id),
    token_hash   text not null unique,
    created_at   timestamptz not null,
    last_used_at timestamptz
);

create table login_throttle (
    key          text primary key,
    failures     int not null,
    window_start timestamptz not null,
    locked_until timestamptz
);

create table billing_run (
    id          bigserial primary key,
    building_id bigint not null references building (id),
    month       text not null check (month ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'),
    budget      numeric(12, 2) not null check (budget > 0),
    created_by  bigint not null references app_user (id),
    created_at  timestamptz not null,
    unique (building_id, month)
);

create table invoice (
    id         bigserial primary key,
    run_id     bigint not null references billing_run (id),
    unit_id    bigint not null references unit (id),
    month      text not null,
    due_date   date not null,
    amount     numeric(12, 2) not null check (amount >= 0),
    our_number text not null unique,
    status     text not null check (status in ('OPEN', 'PAID', 'CANCELLED')),
    paid_on    date,
    unique (unit_id, month)
);

create table payment (
    id          bigserial primary key,
    invoice_id  bigint references invoice (id),
    external_id text not null unique,
    source      text not null check (source in ('STATEMENT', 'WEBHOOK', 'MANUAL')),
    amount      numeric(12, 2) not null check (amount > 0),
    paid_on     date not null,
    memo        text,
    created_at  timestamptz not null
);

create table common_area (
    id          bigserial primary key,
    building_id bigint not null references building (id),
    name        text not null,
    opens       time not null,
    closes      time not null,
    max_hours   int not null check (max_hours > 0),
    capacity    int not null check (capacity > 0),
    fee         numeric(10, 2) not null default 0
);

create table booking (
    id         bigserial primary key,
    area_id    bigint not null references common_area (id),
    unit_id    bigint not null references unit (id),
    user_id    bigint not null references app_user (id),
    starts_at  timestamptz not null,
    ends_at    timestamptz not null,
    guests     int not null check (guests >= 0),
    status     text not null check (status in ('REQUESTED', 'CONFIRMED', 'CANCELLED')),
    created_at timestamptz not null,
    check (ends_at > starts_at)
);

create table notice (
    id           bigserial primary key,
    building_id  bigint not null references building (id),
    author_id    bigint not null references app_user (id),
    title        text not null,
    body         text not null,
    pinned       boolean not null default false,
    published_at timestamptz not null
);

create table document (
    id            bigserial primary key,
    building_id   bigint not null references building (id),
    unit_id       bigint references unit (id),
    kind          text not null check (kind in ('MINUTES', 'RULES', 'STATEMENT', 'PROOF', 'OTHER')),
    original_name text not null,
    stored_name   text not null unique,
    content_type  text not null,
    size_bytes    bigint not null,
    source_url    text,
    uploaded_by   bigint not null references app_user (id),
    created_at    timestamptz not null
);

create table audit_log (
    id      bigserial primary key,
    at      timestamptz not null,
    user_id bigint references app_user (id),
    action  text not null,
    detail  text not null
);

create index unit_building on unit (building_id);
create index residency_unit on residency (unit_id);
create index invoice_status_due on invoice (status, due_date);
create index booking_area_time on booking (area_id, starts_at);
create index booking_unit on booking (unit_id);
create index notice_building on notice (building_id, published_at);
create index document_building on document (building_id);
