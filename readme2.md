# How to write a Table Definition (.tbl)

KISS-PHP allows you to define database tables using simple `.tbl` files. These files are parsed to automatically create or update your database schema and provide an ORM-like interface.

## Basic Syntax

Each line in a `.tbl` file represents a column in the database table. The basic format is:

```
columnName[type]
```

- **columnName**: The name of the column (camelCase is converted to snake_case in the database).
- **type**: The SQL data type.

### Available Types
- `varchar(length)`: Variable length string.
- `int`: Integer.
- `boolean`: Boolean value (tinyint).
- `longtext`: Long text field.
- `date`: Date field.
- *And any other valid SQL type supported by your database driver.*

## Modifiers

You can add constraints and options to a column by appending them after the type, separated by colons `:`.

```
columnName[type]:option1:option2
```

### Supported Modifiers
- `:null`: Allows the column to contain NULL values.
- `:unique`: Enforces a UNIQUE constraint.
- `:primary`: Marks the column as a PRIMARY KEY (automatically handled for `id`).
- `:increment`: Sets AUTO_INCREMENT (usually for primary keys).
- `:unsigned`: For integer types, makes them UNSIGNED.
- `:zero`: For integer types, uses ZEROFILL.
- `:binary`: Treats the column as binary data.

## Default Values

You can specify a default value for a column by wrapping it in parentheses `()` at the end of the definition.

```
isActive[boolean](false)
```

## Relationships (Foreign Keys and References)

In KISS-PHP, you can define relationships between tables using `$` and `@`.

### 1. `$`: Foreign Key (One-to-One / Many-to-One)
To define a standard foreign key relationship where the column in the current table references a primary key in another table, use the `$` symbol. This also implies fetching a **single** related record.

```
userId[int]$User.id
```
- **Meaning**: The column `userId` in the current table is a foreign key pointing to the `id` column of the `User` table.
- **Behavior**:
  - Enforces a FOREIGN KEY constraint in the database.
  - When fetching this row, the ORM can retrieve the related `User` row as a single object (e.g., `$row->getUser()`).

### 2. `@`: Virtual Reference (One-to-Many / Inversed)
To define a relationship where the current table can fetch multiple records from another table that reference *this* table, use the `@` symbol. This does **not** create a database column in the current table; it's a "virtual" field for the ORM.

```
userImages@UserImages.user_id
```
- **Meaning**: The current row (e.g., in `User` table) is referenced by the `user_id` column in the `UserImages` table.
- **Behavior**:
  - Does **not** create a column in the current table.
  - When fetching this row, the ORM can retrieve a **list** of all related `UserImages` rows where `UserImages.user_id` matches the current row's ID (e.g., `$row->getUserImages()`).

### Real-World Example
In `User.tbl`:
```
userProfile$UserProfile.user_id  // Relationship to a single profile
userImages@UserImages.user_id    // Relationship to multiple images
```

## Initial Data Seeding

You can populate the table with initial data by adding a `<default>` block at the end of the file. The data should be formatted as CSV within this block.

```
<default>
column1,column2
value1,value2
value3,value4
</default>
```

**Note**: The parsing logic for this feature is currently not implemented in `TablesManager.php`, but this is the proposed syntax for future support.

## Special Column: `id`

Every table automatically gets an `id` column (Integer, Primary Key, Auto Increment). You do **not** need to define it in your `.tbl` file.

## Complete Example

```
# Example.tbl

# Simple columns
userName[varchar(255)]
userAge[int]

# Modifiers
email[varchar(100)]:unique:null
score[int]:unsigned:zero

# Default value
isAdmin[boolean](false)

# Foreign Key
roleId[int]$Role.id

# Initial Data
<default>
userName,userAge,email,isAdmin
Alice,30,alice@example.com,true
Bob,25,bob@example.com,false
</default>
```

## How it works

1. The `TablesManager` reads files from the `Tables/` directory.
2. It parses the syntax above.
3. It generates the necessary SQL validity checks and table creation/alteration statements (`CREATE TABLE`, `ALTER TABLE`) if the table or columns don't exist.
4. It maps these definitions to `Row` objects for easy PHP interaction.
