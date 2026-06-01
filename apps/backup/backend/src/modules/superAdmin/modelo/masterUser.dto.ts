import { ApiProperty } from '@nestjs/swagger';

export class MasterUserDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'admin@empresa.com' })
  email!: string;

  @ApiProperty({ example: 'Admin' })
  name!: string;

  @ApiProperty({ example: 'admin' })
  role!: string;

  @ApiProperty({ example: true })
  active!: boolean;
}

