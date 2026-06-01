import { ApiProperty } from '@nestjs/swagger';

export class ClientDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'individual' })
  clientType!: string;

  @ApiProperty({ example: 'Juan Perez' })
  firstName!: string;

  @ApiProperty({ example: 'Empresa SAS' })
  companyName!: string;

  @ApiProperty({ example: '9001234567' })
  taxId!: string;

  @ApiProperty({ example: 'Representante Legal' })
  legalRepresentative!: string;

  @ApiProperty({ example: '3000000000' })
  phone!: string;

  @ApiProperty({ example: 'cliente@correo.com' })
  email!: string;

  @ApiProperty({ example: '123456' })
  idNumber!: string;

  @ApiProperty({ example: 'Calle 1 #2-3' })
  address!: string;

  @ApiProperty({ example: 'Notas internas' })
  notes!: string;

  @ApiProperty({ example: 123 })
  clientNumber!: number | null;

  @ApiProperty({ example: '2026-01-01T00:00:00.000Z' })
  createdAt!: string;
}
